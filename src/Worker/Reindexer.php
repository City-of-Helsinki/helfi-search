<?php

declare(strict_types=1);

namespace App\Worker;

use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Indexer\IndexerInterface;
use App\Queue\Action;
use App\Queue\RedisQueue;
use Psr\Log\LoggerInterface;

/**
 * Claims a batch of queued changes and applies them.
 */
final class Reindexer
{
    /**
     * @param CrawlerConfigLoader $config crawler.yml, the full-crawl config.
     */
    public function __construct(
        private readonly RedisQueue $queue,
        private readonly CrawlerConfigLoader $config,
        private readonly LoggerInterface $logger,
        private readonly IndexerInterface $indexer,
    ) {
    }

    /**
     * Claims up to $maxUrls changes and applies them: the deletes, then one
     * crawl of the updated URLs. A batch that fails goes back to the queue,
     * to be retried later.
     *
     * @param int $settleSeconds Only claim changes at least this old.
     * @param int $leaseSeconds The batch's lease, renewed while it's
     *   processed: if this worker dies, others take the batch over after it.
     * @param (\Closure(): bool)|null $shouldStop Called regularly while the
     *   batch is processed: TRUE stops it, and returns it to the queue.
     */
    public function run(
        int $maxUrls,
        int $settleSeconds,
        int $leaseSeconds,
        ?\Closure $shouldStop = null,
    ): BatchResult {
        $batch = $this->queue->claim($maxUrls, $settleSeconds, $leaseSeconds);
        if ($batch === null) {
            return BatchResult::Idle;
        }

        $updates = $batch->urls(Action::Update);
        $deletes = $batch->urls(Action::Delete);
        $this->logger->info('Processing batch {batch}: {updates} update(s), {deletes} delete(s).', [
            'batch' => $batch->id,
            'updates' => count($updates),
            'deletes' => count($deletes),
        ]);

        $lease = new Lease($this->queue, $batch, $this->logger, $leaseSeconds);
        $tick = static function () use ($lease, $shouldStop): void {
            if ($shouldStop !== null && $shouldStop()) {
                throw new Stopped();
            }
            $lease->renew();
        };

        try {
            // Read the config on every run so edits apply without a restart.
            $config = $this->config->load();

            if ($deletes) {
                $deleted = $this->indexer->delete($config, $deletes);
                $this->logger->info('Deleted {count} document(s).', ['count' => $deleted]);
            }
            if ($updates) {
                $tick();
                $this->indexer->crawl($config, $this->targetedConfig($config, $updates), sweep: false, tick: $tick);
            }
        } catch (Stopped) {
            $this->queue->release($batch);
            $this->logger->info('Batch {batch} was returned to the queue: the worker is stopping.', [
                'batch' => $batch->id,
            ]);
            return BatchResult::Stopped;
        } catch (\Throwable $e) {
            $this->queue->release($batch, $e->getMessage());
            $this->logger->error('Batch {batch} failed and was returned to the queue: {error}', [
                'batch' => $batch->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            return BatchResult::Failed;
        }

        $this->queue->complete($batch);
        $this->logger->info('Batch {batch} done.', ['batch' => $batch->id]);
        return BatchResult::Done;
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

        // Keep each domain's own settings, but only for the domains in this
        // batch.
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
        // There's nothing to purge in the crawl's own index, but be sure.
        $result['purge_crawl_enabled'] = false;
        unset($result['schedule']);

        return $result;
    }
}
