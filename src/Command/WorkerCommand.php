<?php

declare(strict_types=1);

namespace App\Command;

use App\Queue\Spool;
use App\Worker\Reindexer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:worker', description: 'Applies the queued changes')]
final class WorkerCommand extends Command implements SignalableCommandInterface
{
    private bool $running = true;

    public function __construct(
        private readonly Spool $queue,
        private readonly Reindexer $reindexer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'every',
                null,
                InputOption::VALUE_REQUIRED,
                'Keep running and process the queue every this many seconds',
            )
            ->setHelp(<<<'HELP'
                By default, processes the queue once and exits: run it on a schedule,
                e.g. as an OpenShift CronJob every 30 minutes.

                With <info>--every</info>, keeps running and processes the queue every so
                many seconds. For places without a scheduler, like docker compose.

                Only one worker runs at a time: a second one exits right away (code 0)
                while the first holds the lock in the queue folder.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('every');
        $every = is_string($option)
            ? filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($every === false) {
            $output->writeln('<error>--every must be a positive number of seconds.</error>');
            return self::INVALID;
        }

        if (!$this->queue->lock()) {
            $this->logger->info('Another worker is running; nothing to do.');
            return self::SUCCESS;
        }

        // A batch left in processing/ belongs to a worker that was killed
        // before it finished (e.g. a CronJob pod past its deadline): put it
        // back.
        if (($released = $this->queue->releaseAll()) > 0) {
            $this->logger->info('Returned {count} unfinished change(s) to the queue.', ['count' => $released]);
        }

        do {
            $ok = $this->reindexer->run();
            if ($every === null) {
                break;
            }

            $this->logger->info('Next run in {seconds}s.', ['seconds' => $every]);
            $until = time() + $every;
            // A signal interrupts the sleep.
            while ($this->running && time() < $until) {
                sleep(max(1, $until - time()));
            }
        } while ($this->running);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Stop between batches on SIGTERM (pod shutdown, `docker compose stop`).
     * A crawl in progress is left to finish; if the process is killed first,
     * the next run returns its batch to the queue.
     *
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->running = false;

        // Keep running until the loop ends.
        return false;
    }
}
