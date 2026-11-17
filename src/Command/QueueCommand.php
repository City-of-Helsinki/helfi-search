<?php

declare(strict_types=1);

namespace App\Command;

use App\Queue\RedisQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:queue', description: 'Shows the queue, and its failed changes')]
final class QueueCommand extends Command
{
    public function __construct(
        private readonly RedisQueue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Queue the failed changes again')
            ->setHelp(<<<'HELP'
                Shows how many changes are waiting, being processed and failed, the
                workers seen recently, and each failed change with its last error.

                A change fails after 5 failed attempts. Fix the cause, then queue them
                again with <info>--retry-failed</info>.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('retry-failed')) {
            $io->success(sprintf('Queued %d failed change(s) again.', $this->queue->retryFailed()));
        }

        $stats = $this->queue->stats();
        $io->definitionList(
            ['Waiting' => $stats['pending']],
            ['Being processed' => sprintf('%d, in %d batch(es)', $stats['processing'], $stats['batches'])],
            ['Failed' => $stats['failed']],
            ['Workers' => $stats['workers'] === [] ? 'none seen recently' : implode(', ', $stats['workers'])],
        );

        $rows = [];
        foreach ($this->queue->failed() as $url => $change) {
            $failedAt = is_numeric($change['failed_at'] ?? null) ? gmdate('c', (int) $change['failed_at']) : '';
            $rows[] = [
                $url,
                is_string($change['action'] ?? null) ? $change['action'] : '',
                $failedAt,
                is_string($change['error'] ?? null) ? $change['error'] : '',
            ];
        }
        if ($rows !== []) {
            $io->table(['URL', 'Action', 'Failed at', 'Last error'], $rows);
        }

        return self::SUCCESS;
    }
}
