<?php

declare(strict_types=1);

namespace App\Command;

use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Indexer\IndexerInterface;
use App\Queue\RedisQueue;
use App\Worker\Elasticsearch;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:crawl', description: 'Crawls every page of the sites in crawler.yml')]
final class CrawlCommand extends Command
{
    /** How often the clean-up after full crawls runs at most. */
    private const int CLEAN_UP_INTERVAL = 20 * 3600;

    public function __construct(
        private readonly CrawlerConfigLoader $config,
        private readonly IndexerInterface $indexer,
        private readonly RedisQueue $queue,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'site',
                null,
                InputOption::VALUE_REQUIRED,
                'Crawl only this site: its host, or its position in crawler.yml (0 is the first)',
            )
            ->addOption(
                'limit-per-domain',
                null,
                InputOption::VALUE_REQUIRED,
                'For development: crawl at most about this many URLs per site; 0 means no limit',
                '0',
            )
            ->setHelp(<<<'HELP'
                By default, runs one crawl of every page of every site in crawler.yml,
                processed into the search index. Then removes the pages it didn't find
                from the search index.

                With <info>--site</info>, crawls only that site, and removes only its pages
                that are gone. Crawls of different sites can run at the same time, e.g. as
                an indexed Job: <info>--site=$(JOB_COMPLETION_INDEX)</info>.

                After a complete crawl, the first crawl to finish in about a day cleans up:
                it removes the vectors no page uses, old deletion records and the indexes
                of crawls that crashed.

                With <info>--limit-per-domain</info>, runs one crawl per site. The crawler
                counts every URL it handles toward the limit, including redirects and
                non-HTML files, so a site gets at most that many documents, usually fewer.
                Limited crawls don't remove anything: that would delete every page past the
                limit. Pages from earlier crawls stay in the index.
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
            $settings = $config->toArray();
            $domains = $config->domains();

            $site = $input->getOption('site');
            if (is_string($site)) {
                $domain = $this->findSite($config, $site);
                if ($domain === null) {
                    $output->writeln("<error>No site $site in crawler.yml.</error>");
                    return self::INVALID;
                }
                $domains = [$domain => $domains[$domain]];
            }

            Elasticsearch::fromConfig($config)->waitUntilReady();

            if ($limit === 0) {
                $this->logger->info('Crawling {sites}.', ['sites' => implode(', ', array_keys($domains))]);
                $this->indexer->crawl($config, ['domains' => array_values($domains)] + $settings, sweep: true);

                if ($this->queue->onceEvery('clean-up', self::CLEAN_UP_INTERVAL)) {
                    $this->indexer->cleanUp($config);
                }
                return self::SUCCESS;
            }

            foreach ($domains as $url => $domain) {
                $this->logger->info('Crawling at most {limit} URLs of {site}.', ['limit' => $limit, 'site' => $url]);
                $this->indexer->crawl($config, [
                    'domains' => [$domain],
                    'max_unique_url_count' => $limit,
                ] + $settings, sweep: false);
            }
        } catch (\Throwable $e) {
            $this->logger->error('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The site's URL in crawler.yml, by its host or position.
     */
    private function findSite(CrawlerConfig $config, string $site): ?string
    {
        $sites = $config->sites();
        if (ctype_digit($site)) {
            return $sites[(int) $site] ?? null;
        }
        foreach ($sites as $url) {
            if (strcasecmp((string) parse_url($url, PHP_URL_HOST), $site) === 0) {
                return $url;
            }
        }

        return null;
    }
}
