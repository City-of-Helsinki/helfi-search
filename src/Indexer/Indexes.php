<?php

declare(strict_types=1);

namespace App\Indexer;

use App\Embedding\EmbeddingModel;

/**
 * The indexes the worker writes, and the crawler's temporary ones.
 *
 * Configured in config/services.yaml.
 */
final readonly class Indexes
{
    /**
     * @param string $search The search index: an alias, see
     *   searchIndexVersion().
     * @param string $embeddingCache Chunk vectors by content hash.
     * @param string $crawlPrefix The start of the crawls' own indexes: see
     *   newCrawlIndex().
     */
    public function __construct(
        public string $search,
        public string $embeddingCache,
        public string $crawlPrefix,
    ) {
    }

    /**
     * A new name for a crawl's own index, e.g.
     * "hel-fi-pages-20261117-120000-1a2b3c4d".
     *
     * Every crawl writes the pages it fetches (with their HTML) into an index
     * of its own, which is processed and deleted when the crawl ends. So
     * crawls running at the same time each process exactly their own pages.
     */
    public function newCrawlIndex(): string
    {
        return sprintf('%s-%s-%s', $this->crawlPrefix, gmdate('Ymd-His'), bin2hex(random_bytes(4)));
    }

    /**
     * A pattern that matches every crawl index.
     */
    public function crawlIndexPattern(): string
    {
        return "$this->crawlPrefix-*";
    }

    /**
     * When the crawl index was created, as a Unix time, from its name.
     *
     * @return int|null NULL if it's not a crawl index.
     */
    public function crawlIndexCreated(string $index): ?int
    {
        $pattern = '/^' . preg_quote($this->crawlPrefix, '/') . '-(\d{8}-\d{6})-[0-9a-f]{8}$/';
        if (!preg_match($pattern, $index, $matches)) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Ymd-His', $matches[1], new \DateTimeZone('UTC'));

        return $time === false ? null : $time->getTimestamp();
    }

    /**
     * The index the search alias points to when it's created, e.g.
     * "hel-fi-v1". A new layout gets a new version, so it can be built while
     * the old one serves searches.
     */
    public function searchIndexVersion(): string
    {
        return "$this->search-v1";
    }

    /**
     * A crawl index: only URLs and crawl times are searchable; the rest
     * (title, full_html, ...) is just stored for the worker.
     *
     * @return array<mixed>
     */
    public static function crawlMapping(): array
    {
        return [
            'mappings' => [
                'dynamic' => false,
                'properties' => [
                    'url' => ['type' => 'keyword'],
                    'additional_urls' => ['type' => 'keyword'],
                    'last_crawled_at' => ['type' => 'date'],
                ],
            ],
        ];
    }

    /**
     * One document per page, with its chunks' vectors. The field names are
     * the ones the helfi_search module's search reads.
     *
     * @return array<mixed>
     */
    public static function searchMapping(): array
    {
        $chunkFields = [];
        foreach (EmbeddingModel::cases() as $model) {
            $chunkFields[$model->field()] = [
                'type' => 'nested',
                'properties' => [
                    'vector' => ['type' => 'dense_vector', 'dims' => $model->dimensions()],
                    'content' => ['type' => 'text', 'index' => false],
                    'fragment' => ['type' => 'keyword', 'index' => false],
                ],
            ];
        }

        return [
            'mappings' => [
                'dynamic' => 'strict',
                // The vectors are only for the kNN search; leaving them out of
                // _source makes searches faster.
                '_source' => [
                    'excludes' => array_map(
                        static fn (EmbeddingModel $model): string => $model->field() . '.vector',
                        EmbeddingModel::cases(),
                    ),
                ],
                'properties' => [
                    'url' => ['type' => 'keyword'],
                    'additional_urls' => ['type' => 'keyword'],
                    'instance' => ['type' => 'keyword'],
                    'label' => [
                        'type' => 'text',
                        'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    'search_api_language' => ['type' => 'keyword'],
                    'entity_bundle' => ['type' => 'keyword'],
                    'og_image' => ['type' => 'keyword', 'index' => false],
                    'markdown' => ['type' => 'text', 'index' => false],
                    'content_hash' => ['type' => 'keyword'],
                    'chunk_hashes' => ['type' => 'keyword'],
                    'last_crawled_at' => ['type' => 'date'],
                    'processed_at' => ['type' => 'date'],
                ] + $chunkFields,
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function embeddingCacheMapping(): array
    {
        return [
            'mappings' => [
                'dynamic' => 'strict',
                'properties' => [
                    'model' => ['type' => 'keyword'],
                    // 32-bit floats, see Vector.
                    'vector' => ['type' => 'binary'],
                ],
            ],
        ];
    }
}
