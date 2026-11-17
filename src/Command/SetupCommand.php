<?php

declare(strict_types=1);

namespace App\Command;

use App\CrawlerConfigLoader;
use App\Indexer\IndexSetup;
use App\Worker\Elasticsearch;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:setup', description: 'Creates the Elasticsearch indexes that are missing')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly CrawlerConfigLoader $config,
        private readonly IndexSetup $setup,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Creates the crawler's index (output_index in crawler.yml), the embedding
            cache and the search index with its alias, if they don't exist. Existing
            indexes are left as they are. Safe to run on every deployment.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->config->load();
            $elasticsearch = Elasticsearch::fromConfig($config);
            $elasticsearch->waitUntilReady();
            $this->setup->run($elasticsearch, $config);
        } catch (\Throwable $e) {
            $this->logger->error('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
