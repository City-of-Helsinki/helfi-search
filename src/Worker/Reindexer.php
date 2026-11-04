<?php

declare(strict_types=1);

namespace App\Worker;

use App\CrawlerConfig;
use App\Log;
use App\Queue\Action;
use App\Queue\Spool;

/**
 * Processes one batch of queued changes.
 */
final class Reindexer
{
    /**
     * @param string $crawlerConfigPath crawler.yml, the full-crawl config.
     */
    public function __construct(
        private readonly Spool $queue,
        private readonly string $crawlerConfigPath,
        private readonly Crawler $crawler = new Crawler(),
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
            Log::info('Queue is empty.');
            return true;
        }

        $updates = array_keys(array_filter($actions, fn (Action $a): bool => $a === Action::Update));
        $deletes = array_keys(array_filter($actions, fn (Action $a): bool => $a === Action::Delete));
        Log::info(sprintf('Processing batch %s: %d update(s), %d delete(s).', $batch, count($updates), count($deletes)));

        try {
            // Read the config on every run so edits apply without a restart.
            $config = CrawlerConfig::fromFile($this->crawlerConfigPath);

            if ($deletes) {
                $es = Elasticsearch::fromConfig($config);
                $deleted = $es->deleteByUrls($deletes);
                Log::info("Deleted $deleted document(s).");
            }
            if ($updates) {
                $this->crawler->crawl($this->targetedConfig($config, $updates));
            }
        } catch (\Throwable $e) {
            $this->queue->release($batch);
            Log::error("Batch $batch failed and was returned to the queue: {$e->getMessage()}");
            return false;
        }

        $this->queue->complete($batch);
        Log::info("Batch $batch done.");
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
                Log::warning("Skipping $url: its site is no longer in crawler.yml.");
                continue;
            }
            $seeds[$domain][] = $url;
        }

        $result = $config->toArray();
        // Keep each domain's own settings (exclude_tags, extraction rules),
        // but only for the domains in this batch.
        $result['domains'] = [];
        foreach ($config->toArray()['domains'] as $domain) {
            if (isset($seeds[$domain['url']])) {
                unset($domain['sitemap_urls']);
                $domain['seed_urls'] = $seeds[$domain['url']];
                $result['domains'][] = $domain;
            }
        }
        if (!$result['domains']) {
            throw new \RuntimeException('None of the URLs belong to a configured site.');
        }

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
