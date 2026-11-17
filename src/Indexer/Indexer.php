<?php

declare(strict_types=1);

namespace App\Indexer;

use App\CrawlerConfig;
use App\Embedding\EmbeddingClient;
use App\Embedding\EmbeddingModel;
use App\Queue\DeletedUrls;
use App\Worker\Crawler;
use App\Worker\Elasticsearch;
use Psr\Log\LoggerInterface;

/**
 * Crawls pages and processes them into the search index. The full crawl and
 * the webhook's updates both go through here, so they index pages the same
 * way.
 *
 *   crawler ──▶ crawl index (HTML) ──▶ PageProcessor ──▶ search index
 *                                            ▲
 *                             embedding cache ┘ (only new chunks are embedded)
 *
 * Each crawl has an index of its own (Indexes::newCrawlIndex()), deleted when
 * it's processed, so any number of crawls can run at once.
 *
 * @phpstan-type Stats array{
 *   pages: int, changed: int, unchanged: int, skipped: int, failed: int, embedded: int, reused: int,
 * }
 */
final readonly class Indexer implements IndexerInterface
{
    /** Pages processed together: one embeddings request, one bulk write. */
    private const int BATCH_SIZE = 20;

    /**
     * How long deletions are remembered, and crawl indexes left by crashed
     * crawls kept: longer than any crawl takes.
     */
    private const int RETENTION = 2 * 86400;

    public function __construct(
        private Crawler $crawler,
        private PageProcessor $processor,
        private EmbeddingClient $embeddings,
        private Indexes $indexes,
        private DeletedUrls $deletedUrls,
        private LoggerInterface $logger,
    ) {
    }

    public function crawl(CrawlerConfig $config, array $crawlConfig, bool $sweep, ?\Closure $tick = null): void
    {
        $elasticsearch = Elasticsearch::fromConfig($config);
        $start = gmdate('Y-m-d\TH:i:s\Z');
        $index = $this->indexes->newCrawlIndex();
        $elasticsearch->request('PUT', "/$index", Indexes::crawlMapping());

        try {
            $crawlError = null;
            try {
                $this->crawler->crawl(['output_index' => $index] + $crawlConfig, $tick);
            } catch (\RuntimeException $e) {
                // Process what was crawled anyway.
                $crawlError = $e;
            }

            $stats = $this->processIndex($elasticsearch, $config, $index, $tick);
        } finally {
            $elasticsearch->deleteIndex($index);
        }

        $this->logger->info(
            'Processed {pages} page(s): {changed} changed, {unchanged} unchanged, {skipped} deleted meanwhile, '
                . '{failed} failed. Embedded {embedded} chunk(s), reused {reused}.',
            $stats,
        );

        if ($crawlError !== null) {
            throw $crawlError;
        }

        // Only after a complete crawl: otherwise the pages it missed would be
        // deleted.
        if ($sweep) {
            $instances = [];
            foreach (is_array($crawlConfig['domains'] ?? null) ? $crawlConfig['domains'] : [] as $domain) {
                if (is_array($domain) && is_string($domain['url'] ?? null)) {
                    $instances[] = (string) parse_url($domain['url'], PHP_URL_HOST);
                }
            }
            $deleted = (new SearchIndex($elasticsearch, $this->indexes->search))
                ->deleteProcessedBefore($start, $instances);
            $this->logger->info('Removed {deleted} page(s) no longer found.', ['deleted' => $deleted]);
        }

        if ($stats['failed'] > 0) {
            throw new \RuntimeException("{$stats['failed']} page(s) failed to process; see the log above.");
        }
    }

    public function delete(CrawlerConfig $config, array $urls): int
    {
        $elasticsearch = Elasticsearch::fromConfig($config);
        $this->deletedUrls->record($urls);

        return (new SearchIndex($elasticsearch, $this->indexes->search))->deleteByUrls($urls);
    }

    public function cleanUp(CrawlerConfig $config): void
    {
        $elasticsearch = Elasticsearch::fromConfig($config);

        $pruned = (new EmbeddingCache($elasticsearch, $this->indexes->embeddingCache))
            ->prune($this->indexes->search);
        $forgotten = $this->deletedUrls->prune(self::RETENTION);

        $removed = 0;
        foreach ($elasticsearch->indexes($this->indexes->crawlIndexPattern()) as $index) {
            $created = $this->indexes->crawlIndexCreated($index);
            if ($created !== null && $created < time() - self::RETENTION) {
                $elasticsearch->deleteIndex($index);
                $removed++;
            }
        }

        $this->logger->info(
            'Removed {pruned} unused vector(s), {forgotten} old deletion record(s) and {removed} abandoned '
                . 'crawl index(es).',
            ['pruned' => $pruned, 'forgotten' => $forgotten, 'removed' => $removed],
        );
    }

    /**
     * @param (\Closure(): void)|null $tick
     * @return Stats
     */
    private function processIndex(
        Elasticsearch $elasticsearch,
        CrawlerConfig $config,
        string $index,
        ?\Closure $tick,
    ): array {
        $stats = [
            'pages' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'embedded' => 0,
            'reused' => 0,
        ];

        $elasticsearch->refresh($index);
        $crawled = $elasticsearch->scan(
            $index,
            ['match_all' => (object) []],
            ['url', 'additional_urls', 'full_html', 'last_crawled_at'],
            self::BATCH_SIZE,
        );

        $batch = [];
        foreach ($crawled as $id => $source) {
            $batch[$id] = $source;
            if (count($batch) === self::BATCH_SIZE) {
                $this->processBatch($elasticsearch, $config, $batch, $stats);
                $batch = [];
                if ($tick !== null) {
                    $tick();
                }
            }
        }
        if ($batch !== []) {
            $this->processBatch($elasticsearch, $config, $batch, $stats);
        }

        return $stats;
    }

    /**
     * @param array<string, array<mixed>> $sources The crawler's documents, by ID.
     * @param Stats $stats
     */
    private function processBatch(
        Elasticsearch $elasticsearch,
        CrawlerConfig $config,
        array $sources,
        array &$stats,
    ): void {
        $model = EmbeddingModel::DEFAULT;
        $search = new SearchIndex($elasticsearch, $this->indexes->search);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stats['pages'] += count($sources);

        /** @var array<string, ProcessedPage> $processed */
        $processed = [];
        $failed = [];
        foreach ($sources as $id => $source) {
            try {
                $page = Page::fromSource($id, $source);
                $processed[$id] = $this->processor->process($page, $this->instance($config, $page->url), $model);
            } catch (\Throwable $e) {
                $failed[] = $id;
                $this->logger->error('Cannot process {url}: {error}', [
                    'url' => is_string($source['url'] ?? null) ? $source['url'] : $id,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $processed = $this->withoutDeleted($processed, $stats);

        // Unchanged pages only get their processing time updated, so the
        // full crawl's sweep keeps them.
        $stored = $search->contentHashes(array_keys($sources));
        $touch = [];
        $changed = [];
        foreach ($processed as $id => $page) {
            if (($stored[$id] ?? null) === $page->contentHash) {
                $touch[$id] = ['processed_at' => $now, 'last_crawled_at' => $page->page->lastCrawledAt];
            } else {
                $changed[$id] = $page;
            }
        }

        try {
            $vectors = $this->vectors($elasticsearch, $changed, $model, $stats);
            $documents = [];
            foreach ($changed as $id => $page) {
                $documents[$id] = $this->document($page, $vectors, $model, $now);
            }
            $search->index($documents);
            $stats['changed'] += count($changed);
        } catch (\Throwable $e) {
            $failed = [...$failed, ...array_keys($changed)];
            $this->logger->error('Cannot index {count} changed page(s): {error}', [
                'count' => count($changed),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        // A page that failed keeps its old document until it's fixed: the
        // sweep must not delete it.
        foreach ($failed as $id) {
            if (isset($stored[$id])) {
                $touch[$id] = ['processed_at' => $now];
            }
        }
        $search->update($touch);
        $stats['unchanged'] += count($processed) - count($changed);
        $stats['failed'] += count($failed);
    }

    /**
     * Leaves out the pages deleted after they were crawled: a crawl that
     * fetched a page just before it was deleted must not add it back.
     *
     * @param array<string, ProcessedPage> $pages
     * @param Stats $stats
     * @return array<string, ProcessedPage>
     */
    private function withoutDeleted(array $pages, array &$stats): array
    {
        $urls = [];
        foreach ($pages as $page) {
            $urls = [...$urls, $page->page->url, ...$page->page->additionalUrls];
        }
        $deletedAt = $this->deletedUrls->deletedAt($urls);
        if ($deletedAt === []) {
            return $pages;
        }

        foreach ($pages as $id => $page) {
            $crawledAt = strtotime((string) $page->page->lastCrawledAt);
            foreach ([$page->page->url, ...$page->page->additionalUrls] as $url) {
                // Seconds on both sides: a tie means it may have been crawled
                // before the delete.
                if ($crawledAt !== false && isset($deletedAt[$url]) && $deletedAt[$url] >= $crawledAt) {
                    unset($pages[$id]);
                    $stats['skipped']++;
                    $this->logger->info('Skipped {url}: it was deleted after it was crawled.', ['url' => $url]);
                    break;
                }
            }
        }

        return $pages;
    }

    /**
     * The vectors of the changed pages' chunks: from the cache, or embedded
     * and cached.
     *
     * @param array<string, ProcessedPage> $pages
     * @param Stats $stats
     * @return array<string, list<float>> By cache key.
     */
    private function vectors(Elasticsearch $elasticsearch, array $pages, EmbeddingModel $model, array &$stats): array
    {
        $chunks = [];
        foreach ($pages as $page) {
            $chunks += $page->chunks;
        }
        if ($chunks === []) {
            return [];
        }

        $cache = new EmbeddingCache($elasticsearch, $this->indexes->embeddingCache);
        $vectors = $cache->get(array_keys($chunks));
        $stats['reused'] += count($vectors);

        $missing = array_diff_key($chunks, $vectors);
        if ($missing !== []) {
            $new = $this->embeddings->embed(array_map('strval', $missing), $model);
            $cache->put($new, $model);
            $vectors += $new;
            $stats['embedded'] += count($new);
        }

        return $vectors;
    }

    /**
     * @param array<string, list<float>> $vectors By cache key.
     * @return array<string, mixed>
     */
    private function document(ProcessedPage $page, array $vectors, EmbeddingModel $model, string $now): array
    {
        $chunks = [];
        foreach ($page->chunks as $key => $chunk) {
            $chunks[] = [
                'vector' => $vectors[$key] ?? throw new \LogicException("No vector for chunk $key."),
                'content' => $chunk->snippet ?? '',
                'fragment' => $chunk->fragment,
            ];
        }

        return $page->fields + [
            'content_hash' => $page->contentHash,
            'last_crawled_at' => $page->page->lastCrawledAt,
            'processed_at' => $now,
            $model->field() => $chunks,
        ];
    }

    /**
     * The site's host, e.g. "nuorten.hel.fi".
     */
    private function instance(CrawlerConfig $config, string $url): string
    {
        $site = $config->domainFor($url) ?? $url;

        return (string) parse_url($site, PHP_URL_HOST);
    }
}
