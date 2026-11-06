<?php

declare(strict_types=1);

namespace App\Indexer;

use App\Embedding\EmbeddingModel;

/**
 * The indexes the worker writes, besides the crawler's own (output_index in
 * crawler.yml: the crawled pages with their HTML).
 *
 * Configured in config/services.yaml.
 */
final readonly class Indexes
{
    /**
     * @param string $search The search index: an alias, see
     *   searchIndexVersion().
     * @param string $embeddingCache Chunk vectors by content hash.
     */
    public function __construct(
        public string $search,
        public string $embeddingCache,
    ) {
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
     * The crawler's index: only URLs and crawl times are searchable; the
     * rest (title, full_html, ...) is just stored for the worker.
     *
     * @return array<mixed>
     */
    public static function pagesMapping(): array
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
