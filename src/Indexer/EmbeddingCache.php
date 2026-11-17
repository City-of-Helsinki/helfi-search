<?php

declare(strict_types=1);

namespace App\Indexer;

use App\Embedding\EmbeddingModel;
use App\Embedding\Vector;
use App\Pipeline\Chunk;
use App\Worker\Elasticsearch;

/**
 * Chunk vectors by the chunk's content hash, so a chunk is embedded only
 * once: unchanged chunks of a changed page, and chunks repeated across pages
 * or sites, reuse the stored vector.
 */
final readonly class EmbeddingCache
{
    public function __construct(
        private Elasticsearch $elasticsearch,
        private string $index,
    ) {
    }

    /**
     * The cache key of a chunk: changes with its text, its context and the
     * pipeline version (Chunk::contentHash()), and the model.
     */
    public static function key(EmbeddingModel $model, Chunk $chunk): string
    {
        return hash('sha256', $model->value . "\0" . $chunk->contentHash());
    }

    /**
     * @param list<string> $keys
     * @return array<string, list<float>> The stored vectors, by key.
     */
    public function get(array $keys): array
    {
        $vectors = [];
        foreach ($this->elasticsearch->mget($this->index, $keys, ['vector']) as $key => $source) {
            if (is_string($source['vector'] ?? null)) {
                $vectors[$key] = Vector::decode($source['vector']);
            }
        }

        return $vectors;
    }

    /**
     * @param array<string, list<float>> $vectors By key.
     */
    public function put(array $vectors, EmbeddingModel $model): void
    {
        $lines = [];
        foreach ($vectors as $key => $vector) {
            $lines[] = ['index' => ['_index' => $this->index, '_id' => $key]];
            $lines[] = ['model' => $model->value, 'vector' => Vector::encode($vector)];
        }
        $this->elasticsearch->bulk($lines);
    }

    /**
     * Deletes the vectors no search document uses (its chunk_hashes).
     *
     * @return int The number of deleted vectors.
     */
    public function prune(string $searchIndex): int
    {
        $this->elasticsearch->refresh($searchIndex);
        $this->elasticsearch->refresh($this->index);

        $deleted = 0;
        $batch = [];
        foreach ($this->elasticsearch->scan($this->index, ['match_all' => (object) []], false, 1000) as $key => $_) {
            $batch[] = $key;
            if (count($batch) === 1000) {
                $deleted += $this->deleteUnused($searchIndex, $batch);
                $batch = [];
            }
        }

        return $deleted + $this->deleteUnused($searchIndex, $batch);
    }

    /**
     * @param list<string> $keys
     */
    private function deleteUnused(string $searchIndex, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $result = $this->elasticsearch->request('POST', "/$searchIndex/_search", [
            'size' => 0,
            'query' => ['terms' => ['chunk_hashes' => $keys]],
            'aggs' => [
                'used' => ['terms' => ['field' => 'chunk_hashes', 'include' => $keys, 'size' => count($keys)]],
            ],
        ]);
        $aggregations = is_array($result['aggregations'] ?? null) ? $result['aggregations'] : [];
        $used = is_array($aggregations['used'] ?? null) ? $aggregations['used'] : [];
        $buckets = is_array($used['buckets'] ?? null) ? $used['buckets'] : [];
        $usedKeys = [];
        foreach ($buckets as $bucket) {
            if (is_array($bucket) && is_string($bucket['key'] ?? null)) {
                $usedKeys[] = $bucket['key'];
            }
        }

        $lines = [];
        foreach (array_diff($keys, $usedKeys) as $key) {
            $lines[] = ['delete' => ['_index' => $this->index, '_id' => $key]];
        }
        $this->elasticsearch->bulk($lines);

        return count($lines);
    }
}
