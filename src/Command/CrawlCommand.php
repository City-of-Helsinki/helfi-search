<?php

declare(strict_types=1);

namespace App\Command;

use App\CrawlerConfigLoader;
use App\Indexer\IndexerInterface;
use App\Worker\Elasticsearch;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:crawl', description: 'Crawls every site in crawler.yml')]
final class CrawlCommand extends Command
{
    public function __construct(
        private readonly CrawlerConfigLoader $config,
        private readonly IndexerInterface $indexer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit-per-domain',
                null,
                InputOption::VALUE_REQUIRED,
                'For development: crawl at most about this many URLs per site; 0 means no limit',
                '0',
            )
            ->setHelp(<<<'HELP'
                By default, runs one crawl with crawler.yml as is: every page of every
                site, processed into the search index. Then removes the pages that are
                gone, from the crawler's index and the search index.

                With <info>--limit-per-domain</info>, runs one crawl per site, without the
                purge step. The crawler counts every URL it handles toward the limit,
                including redirects and non-HTML files, so a site gets at most that many
                documents, usually fewer.

                Limited crawls don't remove anything: that would delete every page the
                crawl didn't visit, including the other sites and every page past the
                limit. Pages from earlier crawls stay in the indexes.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('limit-per-domain');
        $limit = is_string($option)
            ? filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;
        if ($limit === false) {
            $output->writeln('<error>--limit-per-domain must be 0 or more.</error>');
            return self::INVALID;
        }

        try {
            $config = $this->config->load();
            Elasticsearch::fromConfig($config)->waitUntilReady();
            $settings = $config->toArray();

            if ($limit === 0) {
                $this->logger->info('Crawling all sites.');
                $this->indexer->crawl($config, $settings, sweep: true);
                return self::SUCCESS;
            }

            foreach ($config->domains() as $domain) {
                $this->logger->info('Crawling at most {limit} URLs of {site}.', [
                    'limit' => $limit,
                    'site' => $domain['url'],
                ]);
                $this->indexer->crawl($config, [
                    'domains' => [$domain],
                    'max_unique_url_count' => $limit,
                    'purge_crawl_enabled' => false,
                ] + $settings, sweep: false);
            }
        } catch (\Throwable $e) {
            $this->logger->error('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
