<?php

declare(strict_types=1);

namespace App\Worker;

use App\CrawlerConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * The Elasticsearch requests the worker makes, over its REST API.
 */
final class Elasticsearch
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {
    }

    /**
     * Connects as configured in crawler.yml, with its environment overrides.
     */
    public static function fromConfig(CrawlerConfig $config): self
    {
        $options = ['base_uri' => $config->elasticsearchUrl(), RequestOptions::TIMEOUT => 60];

        $credentials = $config->elasticsearchCredentials();
        if (isset($credentials['api_key'])) {
            $options[RequestOptions::HEADERS] = ['Authorization' => 'ApiKey ' . $credentials['api_key']];
        } elseif (isset($credentials['username'])) {
            $options[RequestOptions::AUTH] = [$credentials['username'], $credentials['password'] ?? ''];
        }

        return new self(new Client($options));
    }

    /**
     * Waits until the cluster accepts requests (status yellow or green).
     *
     * @throws \RuntimeException If it isn't ready in time.
     */
    public function waitUntilReady(int $timeout = 60): void
    {
        $until = time() + $timeout;
        do {
            try {
                $response = $this->client->request('GET', '/_cluster/health', [
                    RequestOptions::QUERY => ['wait_for_status' => 'yellow', 'timeout' => '1s'],
                    RequestOptions::HTTP_ERRORS => false,
                    RequestOptions::TIMEOUT => 5,
                ]);
                $health = json_decode((string) $response->getBody(), true);
                if (is_array($health) && in_array($health['status'] ?? null, ['green', 'yellow'], true)) {
                    return;
                }
            } catch (GuzzleException) {
                // Not reachable yet.
            }
            sleep(1);
        } while (time() < $until);

        throw new \RuntimeException("Elasticsearch was not ready within {$timeout}s.");
    }

    /**
     * Sends a request and returns the decoded response.
     *
     * @param array<mixed>|null $body
     * @param array<string, string|int> $query
     * @param list<int> $allowed Error statuses that return NULL instead of
     *   throwing, e.g. 404 for a missing index.
     * @return array<mixed>|null
     *
     * @throws \RuntimeException On other errors.
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $allowed = [],
    ): ?array {
        $options = [RequestOptions::QUERY => $query, RequestOptions::HTTP_ERRORS => false];
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        try {
            $response = $this->client->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Elasticsearch request $method $path failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $status = $response->getStatusCode();
        if (in_array($status, $allowed, true)) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                "Elasticsearch returned HTTP $status for $method $path: {$response->getBody()}",
            );
        }

        $content = (string) $response->getBody();
        $decoded = $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether an index or alias exists.
     */
    public function exists(string $index): bool
    {
        return $this->request('HEAD', "/$index", allowed: [404]) !== null;
    }

    /**
     * Makes recent writes visible to searches.
     */
    public function refresh(string $index): void
    {
        $this->request('POST', "/$index/_refresh", allowed: [404]);
    }

    /**
     * Deletes the documents of the given URLs, also when a URL is one of a
     * document's additional URLs (pages stored under a canonical URL).
     *
     * @param list<string> $urls
     * @return int The number of deleted documents. A missing index has
     *   none.
     */
    public function deleteByUrls(string $index, array $urls): int
    {
        return $this->deleteByQuery($index, [
            'bool' => [
                'should' => [
                    ['terms' => ['url' => $urls]],
                    ['terms' => ['additional_urls' => $urls]],
                ],
                'minimum_should_match' => 1,
            ],
        ]);
    }

    /**
     * @param array<mixed> $query
     * @return int The number of deleted documents. A missing index has
     *   none.
     */
    public function deleteByQuery(string $index, array $query): int
    {
        $result = $this->request(
            'POST',
            "/$index/_delete_by_query",
            ['query' => $query],
            ['refresh' => 'true', 'conflicts' => 'proceed'],
            allowed: [404],
        );

        return is_int($result['deleted'] ?? null) ? $result['deleted'] : 0;
    }

    /**
     * Sends bulk operations.
     *
     * @param list<array<mixed>> $lines The actions and their documents, one
     *   array per NDJSON line.
     *
     * @throws \RuntimeException If any operation fails.
     */
    public function bulk(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $body = '';
        foreach ($lines as $line) {
            $body .= json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        try {
            $response = $this->client->request('POST', '/_bulk', [
                RequestOptions::BODY => $body,
                RequestOptions::HEADERS => ['Content-Type' => 'application/x-ndjson'],
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Elasticsearch bulk request failed: {$e->getMessage()}", previous: $e);
        }

        $status = $response->getStatusCode();
        $result = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || !is_array($result)) {
            throw new \RuntimeException(
                "Elasticsearch returned HTTP $status for a bulk request: {$response->getBody()}",
            );
        }
        if (($result['errors'] ?? false) !== true) {
            return;
        }

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        foreach ($items as $item) {
            $operation = is_array($item) ? current($item) : null;
            if (is_array($operation) && isset($operation['error'])) {
                $failed = count(array_filter(
                    $items,
                    static fn (mixed $i): bool => is_array($i) && is_array(current($i)) && isset(current($i)['error']),
                ));
                throw new \RuntimeException(sprintf(
                    '%d bulk operation(s) failed, e.g. for %s: %s',
                    $failed,
                    is_scalar($operation['_id'] ?? null) ? $operation['_id'] : '?',
                    json_encode($operation['error']),
                ));
            }
        }
    }

    /**
     * Reads documents by ID.
     *
     * @param list<string> $ids
     * @param list<string>|bool $source Which fields to read.
     * @return array<string, array<mixed>> The found documents' sources, by
     *   ID. Missing documents and a missing index are left out.
     */
    public function mget(string $index, array $ids, array|bool $source = true): array
    {
        if ($ids === []) {
            return [];
        }

        $result = $this->request(
            'POST',
            "/$index/_mget",
            ['ids' => $ids],
            ['_source' => is_array($source) ? implode(',', $source) : ($source ? 'true' : 'false')],
            allowed: [404],
        );

        $found = [];
        foreach (is_array($result['docs'] ?? null) ? $result['docs'] : [] as $doc) {
            if (is_array($doc) && ($doc['found'] ?? false) === true && is_string($doc['_id'] ?? null)) {
                $found[$doc['_id']] = is_array($doc['_source'] ?? null) ? $doc['_source'] : [];
            }
        }

        return $found;
    }

    /**
     * Reads every document that matches a query, in batches, with a point in
     * time: documents written meanwhile don't change the result.
     *
     * @param array<mixed> $query
     * @param list<string>|bool $source Which fields to read.
     * @return \Generator<string, array<mixed>> The sources, by document ID.
     *   A missing index has none.
     */
    public function scan(string $index, array $query, array|bool $source = true, int $batchSize = 100): \Generator
    {
        $pit = $this->request('POST', "/$index/_pit", query: ['keep_alive' => '5m'], allowed: [404]);
        $pitId = $pit['id'] ?? null;
        if (!is_string($pitId)) {
            return;
        }

        try {
            $after = null;
            do {
                $body = [
                    'size' => $batchSize,
                    'query' => $query,
                    '_source' => $source,
                    'pit' => ['id' => $pitId, 'keep_alive' => '5m'],
                    'sort' => ['_shard_doc'],
                    'track_total_hits' => false,
                ];
                if ($after !== null) {
                    $body['search_after'] = $after;
                }
                $result = $this->request('POST', '/_search', $body);
                $pitId = is_string($result['pit_id'] ?? null) ? $result['pit_id'] : $pitId;
                $hits = is_array($result['hits'] ?? null) && is_array($result['hits']['hits'] ?? null)
                    ? $result['hits']['hits']
                    : [];

                foreach ($hits as $hit) {
                    if (is_array($hit) && is_string($hit['_id'] ?? null)) {
                        yield $hit['_id'] => is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
                        $after = $hit['sort'] ?? null;
                    }
                }
            } while (count($hits) === $batchSize && $after !== null);
        } finally {
            $this->request('DELETE', '/_pit', ['id' => $pitId], allowed: [404]);
        }
    }
}
