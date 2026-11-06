<?php

declare(strict_types=1);

namespace App\Worker;

use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Queue\Action;
use App\Queue\Spool;
use Psr\Log\LoggerInterface;

/**
 * Processes one batch of queued changes.
 */
final class Reindexer
{
    /**
     * @param CrawlerConfigLoader $config crawler.yml, the full-crawl config.
     */
    public function __construct(
        private readonly Spool $queue,
        private readonly CrawlerConfigLoader $config,
        private readonly LoggerInterface $logger,
        private readonly Crawler $crawler,
    ) {
    }

    /**
     * Claims the pending changes and applies them. A failed batch goes back
     * to the queue and is retried on the next run.
     *
     * @return bool FALSE if the batch failed.
     */
    public function run(): bool
    {
        [$batch, $actions] = $this->queue->claim();
        if (!$actions) {
            $this->logger->info('Queue is empty.');
            return true;
        }

        $updates = array_keys(array_filter($actions, fn (Action $a): bool => $a === Action::Update));
        $deletes = array_keys(array_filter($actions, fn (Action $a): bool => $a === Action::Delete));
        $this->logger->info('Processing batch {batch}: {updates} update(s), {deletes} delete(s).', [
            'batch' => $batch,
            'updates' => count($updates),
            'deletes' => count($deletes),
        ]);

        try {
            // Read the config on every run so edits apply without a restart.
            $config = $this->config->load();

            if ($deletes) {
                $es = Elasticsearch::fromConfig($config);
                $deleted = $es->deleteByUrls($deletes);
                $this->logger->info('Deleted {count} document(s).', ['count' => $deleted]);
            }
            if ($updates) {
                $this->crawler->crawl($this->targetedConfig($config, $updates));
            }
        } catch (\Throwable $e) {
            $this->queue->release($batch);
            $this->logger->error('Batch {batch} failed and was returned to the queue: {error}', [
                'batch' => $batch,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            return false;
        }

        $this->queue->complete($batch);
        $this->logger->info('Batch {batch} done.', ['batch' => $batch]);
        return true;
    }

    /**
     * Builds a crawler config that fetches only the given URLs.
     *
     * @param list<string> $urls
     * @return array<string, mixed>
     */
    private function targetedConfig(CrawlerConfig $config, array $urls): array
    {
        $seeds = [];
        foreach ($urls as $url) {
            $domain = $config->domainFor($url);
            // The webhook checks this, but crawler.yml may have changed since.
            if ($domain === null) {
                $this->logger->warning('Skipping {url}: its site is no longer in crawler.yml.', ['url' => $url]);
                continue;
            }
            $seeds[$domain][] = $url;
        }

        // Keep each domain's own settings (exclude_tags, extraction rules),
        // but only for the domains in this batch.
        $domains = [];
        foreach ($config->domains() as $site => $domain) {
            if (isset($seeds[$site])) {
                unset($domain['sitemap_urls']);
                $domain['seed_urls'] = $seeds[$site];
                $domains[] = $domain;
            }
        }
        if (!$domains) {
            throw new \RuntimeException('None of the URLs belong to a configured site.');
        }

        $result = $config->toArray();
        $result['domains'] = $domains;

        // Seeds are depth 1, so this stops the crawler from following links.
        $result['max_crawl_depth'] = 1;
        // Don't read the sitemaps listed in robots.txt.
        $result['sitemap_discovery_disabled'] = true;
        // Essential: a purge would delete every document this crawl didn't
        // visit, i.e. the rest of the index.
        $result['purge_crawl_enabled'] = false;
        unset($result['schedule']);

        return $result;
    }
}
