<?php

declare(strict_types=1);

namespace App\Command;

use App\Queue\RedisQueue;
use App\Worker\BatchResult;
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
    /** How long a batch is ours without renewing its lease. */
    public const int LEASE_SECONDS = 600;

    /** How often to touch the liveness file at most. */
    private const int ALIVE_INTERVAL = 10;

    private bool $running = true;
    private string $aliveFile = '';
    private int $aliveAt = 0;

    public function __construct(
        private readonly RedisQueue $queue,
        private readonly Reindexer $reindexer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Exit when nothing is left to claim')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'At most this many URLs per batch', '200')
            ->addOption(
                'settle',
                null,
                InputOption::VALUE_REQUIRED,
                'Only claim changes at least this many seconds old',
                '30',
            )
            ->addOption('poll', null, InputOption::VALUE_REQUIRED, 'Seconds between checks of an empty queue', '5')
            ->addOption(
                'alive-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Touched regularly, for a liveness probe',
                sys_get_temp_dir() . '/worker-alive',
            )
            ->setHelp(<<<'HELP'
                Keeps running: claims a batch of changes, applies it, and repeats. When
                nothing can be claimed, checks again every <info>--poll</info> seconds.

                Any number of workers can run at once: each claims its own batches, and a
                URL is only ever in one batch at a time. A worker that dies leaves its
                batch to the others, once the batch's lease expires.

                <info>--settle</info> waits until a URL has had no new changes for that long:
                a site often sends several in a row for one save.

                With <info>--once</info>, exits when nothing is left to claim, e.g. to process
                the queue by hand (with <info>--settle=0</info>). Exits with code 1 if a batch
                failed.

                On SIGTERM, stops the crawl in progress, returns its batch to the queue and
                exits.

                For a liveness probe, it touches <info>--alive-file</info> at least every
                minute while it's working or waiting.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [];
        foreach (['batch-size' => 1, 'settle' => 0, 'poll' => 1] as $name => $min) {
            $value = $input->getOption($name);
            $options[$name] = is_string($value)
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]])
                : false;
            if ($options[$name] === false) {
                $output->writeln("<error>--$name must be a whole number, at least $min.</error>");
                return self::INVALID;
            }
        }
        [$batchSize, $settle, $poll] = [$options['batch-size'], $options['settle'], $options['poll']];
        $once = (bool) $input->getOption('once');
        $aliveFile = $input->getOption('alive-file');
        $this->aliveFile = is_string($aliveFile) ? $aliveFile : '';
        $worker = gethostname() ?: 'worker';

        $failed = false;
        $idle = false;
        $shouldStop = function (): bool {
            $this->alive();
            return !$this->running;
        };

        while ($this->running) {
            try {
                $this->alive(force: true);
                $this->queue->heartbeat($worker);
                $result = $this->reindexer->run($batchSize, $settle, self::LEASE_SECONDS, $shouldStop);
            } catch (\Throwable $e) {
                // Redis is unreachable, or failing over: wait and try again.
                $this->logger->error('Cannot claim changes: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
                $result = BatchResult::Failed;
                if (!$once) {
                    $this->sleep($poll);
                }
            }
            $failed = $failed || $result === BatchResult::Failed;

            if ($result === BatchResult::Idle) {
                if ($once) {
                    break;
                }
                if (!$idle) {
                    $this->logger->info('Waiting for changes.');
                }
                $idle = true;
                $this->sleep($poll);
            } else {
                $idle = false;
            }
            if ($once && $result === BatchResult::Failed) {
                break;
            }
        }

        if ($once && !$failed) {
            $this->logger->info('Nothing left to claim.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Sleeps, unless a signal says to stop.
     */
    private function sleep(int $seconds): void
    {
        $until = time() + $seconds;
        // A signal interrupts the sleep.
        while ($this->running && time() < $until) {
            sleep(max(1, $until - time()));
        }
    }

    /**
     * Touches the liveness file, at most every ALIVE_INTERVAL seconds unless
     * forced.
     */
    private function alive(bool $force = false): void
    {
        if ($this->aliveFile === '' || (!$force && time() - $this->aliveAt < self::ALIVE_INTERVAL)) {
            return;
        }
        $this->aliveAt = time();
        if (!@touch($this->aliveFile)) {
            $this->logger->warning('Cannot touch {file}.', ['file' => $this->aliveFile]);
            $this->aliveFile = '';
        }
    }

    /**
     * Stop on SIGTERM (pod shutdown, `docker compose stop`): the batch in
     * progress goes back to the queue.
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
