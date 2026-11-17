<?php

declare(strict_types=1);

namespace App\Indexer;

use App\CrawlerConfig;
use App\Embedding\EmbeddingClient;
use App\Embedding\EmbeddingModel;
use App\Worker\Crawler;
use App\Worker\Elasticsearch;
use Psr\Log\LoggerInterface;

/**
 * Crawls pages and processes them into the search index. The full crawl and
 * the webhook's updates both go through here, so they index pages the same
 * way.
 *
 *   crawler ──▶ crawler's index (HTML) ──▶ PageProcessor ──▶ search index
 *                                                ▲
 *                                 embedding cache ┘ (only new chunks are embedded)
 */
final readonly class Indexer implements IndexerInterface
{
    /** Pages processed together: one embeddings request, one bulk write. */
    private const int BATCH_SIZE = 20;

    public function __construct(
        private Crawler $crawler,
        private PageProcessor $processor,
        private EmbeddingClient $embeddings,
        private Indexes $indexes,
        private LoggerInterface $logger,
    ) {
    }

    public function crawl(CrawlerConfig $config, array $crawlConfig, bool $sweep): void
    {
        $elasticsearch = Elasticsearch::fromConfig($config);
        // The crawler stores last_crawled_at in whole seconds.
        $start = gmdate('Y-m-d\TH:i:s\Z');

        $this->crawler->crawl($crawlConfig);

        $stats = $this->processCrawledSince($elasticsearch, $config, $start);
        $this->logger->info(
            'Processed {pages} page(s): {changed} changed, {unchanged} unchanged, {failed} failed. '
                . 'Embedded {embedded} chunk(s), reused {reused}.',
            $stats,
        );

        if ($sweep) {
            $search = new SearchIndex($elasticsearch, $this->indexes->search);
            $deleted = $search->deleteProcessedBefore($start);
            $pruned = (new EmbeddingCache($elasticsearch, $this->indexes->embeddingCache))
                ->prune($this->indexes->search);
            $this->logger->info('Removed {deleted} page(s) no longer found, and {pruned} unused vector(s).', [
                'deleted' => $deleted,
                'pruned' => $pruned,
            ]);
        }

        if ($stats['failed'] > 0) {
            throw new \RuntimeException("{$stats['failed']} page(s) failed to process; see the log above.");
        }
    }

    public function delete(CrawlerConfig $config, array $urls): int
    {
        $elasticsearch = Elasticsearch::fromConfig($config);
        $elasticsearch->deleteByUrls($config->index(), $urls);

        return (new SearchIndex($elasticsearch, $this->indexes->search))->deleteByUrls($urls);
    }

    /**
     * @return array{pages: int, changed: int, unchanged: int, failed: int, embedded: int, reused: int}
     */
    private function processCrawledSince(Elasticsearch $elasticsearch, CrawlerConfig $config, string $since): array
    {
        $stats = ['pages' => 0, 'changed' => 0, 'unchanged' => 0, 'failed' => 0, 'embedded' => 0, 'reused' => 0];

        $elasticsearch->refresh($config->index());
        $crawled = $elasticsearch->scan(
            $config->index(),
            ['range' => ['last_crawled_at' => ['gte' => $since]]],
            ['url', 'additional_urls', 'full_html', 'last_crawled_at'],
            self::BATCH_SIZE,
        );

        $batch = [];
        foreach ($crawled as $id => $source) {
            $batch[$id] = $source;
            if (count($batch) === self::BATCH_SIZE) {
                $this->processBatch($elasticsearch, $config, $batch, $stats);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->processBatch($elasticsearch, $config, $batch, $stats);
        }

        return $stats;
    }

    /**
     * @param array<string, array<mixed>> $sources The crawler's documents, by ID.
     * @param array{pages: int, changed: int, unchanged: int, failed: int, embedded: int, reused: int} $stats
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
     * The vectors of the changed pages' chunks: from the cache, or embedded
     * and cached.
     *
     * @param array<string, ProcessedPage> $pages
     * @param array{pages: int, changed: int, unchanged: int, failed: int, embedded: int, reused: int} $stats
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
