<?php

declare(strict_types=1);

namespace App\Indexer;

use App\Worker\Elasticsearch;

/**
 * Writes to the search index.
 */
final readonly class SearchIndex
{
    public function __construct(
        private Elasticsearch $elasticsearch,
        private string $index,
    ) {
    }

    /**
     * @param list<string> $ids
     * @return array<string, array{hash: string, crawledAt: string|null}> The
     *   stored documents' content hashes and crawl times, by ID. Documents
     *   that aren't stored are left out.
     */
    public function stored(array $ids): array
    {
        $stored = [];
        $sources = $this->elasticsearch->mget($this->index, $ids, ['content_hash', 'last_crawled_at']);
        foreach ($sources as $id => $source) {
            $stored[$id] = [
                'hash' => is_string($source['content_hash'] ?? null) ? $source['content_hash'] : '',
                'crawledAt' => is_string($source['last_crawled_at'] ?? null) ? $source['last_crawled_at'] : null,
            ];
        }

        return $stored;
    }

    /**
     * Writes whole documents.
     *
     * @param array<string, array<string, mixed>> $documents By ID.
     */
    public function index(array $documents): void
    {
        $lines = [];
        foreach ($documents as $id => $document) {
            $lines[] = ['index' => ['_index' => $this->index, '_id' => $id]];
            $lines[] = $document;
        }
        $this->elasticsearch->bulk($lines);
    }

    /**
     * Updates some fields of stored documents.
     *
     * @param array<string, array<string, mixed>> $fields By ID.
     */
    public function update(array $fields): void
    {
        $lines = [];
        foreach ($fields as $id => $values) {
            $lines[] = ['update' => ['_index' => $this->index, '_id' => $id]];
            $lines[] = ['doc' => $values];
        }
        $this->elasticsearch->bulk($lines);
    }

    /**
     * Deletes the given sites' documents not processed since the given time:
     * pages the full crawl didn't find.
     *
     * @param list<string> $instances The sites' hosts, e.g. "nuorten.hel.fi".
     * @return int The number of deleted documents.
     */
    public function deleteProcessedBefore(string $time, array $instances): int
    {
        if ($instances === []) {
            return 0;
        }

        return $this->elasticsearch->deleteByQuery($this->index, [
            'bool' => [
                'filter' => [['terms' => ['instance' => $instances]]],
                'should' => [
                    ['range' => ['processed_at' => ['lt' => $time]]],
                    ['bool' => ['must_not' => ['exists' => ['field' => 'processed_at']]]],
                ],
                'minimum_should_match' => 1,
            ],
        ]);
    }

    /**
     * @param list<string> $urls
     * @return int The number of deleted documents.
     */
    public function deleteByUrls(array $urls): int
    {
        return $this->elasticsearch->deleteByUrls($this->index, $urls);
    }
}
