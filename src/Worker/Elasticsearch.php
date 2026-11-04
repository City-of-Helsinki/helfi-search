<?php

declare(strict_types=1);

namespace App\Worker;

use App\CrawlerConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * The small part of the Elasticsearch API the worker needs.
 */
final class Elasticsearch
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $index,
    ) {
    }

    /**
     * A client for the cluster and index in crawler.yml, with its credentials.
     */
    public static function fromConfig(CrawlerConfig $config): self
    {
        $options = ['base_uri' => $config->elasticsearchUrl(), RequestOptions::TIMEOUT => 60];

        $credentials = $config->elasticsearchCredentials();
        if (isset($credentials['api_key'])) {
            $options[RequestOptions::HEADERS] = ['Authorization' => 'ApiKey ' . $credentials['api_key']];
        }
        elseif (isset($credentials['username'])) {
            $options[RequestOptions::AUTH] = [$credentials['username'], $credentials['password'] ?? ''];
        }

        return new self(new Client($options), $config->index());
    }

    /**
     * Waits until the cluster accepts requests (status yellow or green).
     *
     * A single node can't allocate replicas, so the cluster stays yellow once
     * it has indexes with replicas.
     *
     * @throws \RuntimeException If it isn't ready within $timeout seconds.
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
                $status = json_decode((string) $response->getBody(), true)['status'] ?? null;
                if (in_array($status, ['green', 'yellow'], true)) {
                    return;
                }
            } catch (\GuzzleHttp\Exception\GuzzleException) {
                // Not reachable yet.
            }
            sleep(1);
        } while (time() < $until);

        throw new \RuntimeException("Elasticsearch was not ready within {$timeout}s.");
    }

    /**
     * Deletes the documents crawled from the given URLs.
     *
     * Matches both the document URL and additional_urls, which holds the
     * crawled URL when the page's canonical URL differs from it.
     *
     * @param list<string> $urls
     * @return int The number of deleted documents.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function deleteByUrls(array $urls): int
    {
        $response = $this->client->request('POST', "/{$this->index}/_delete_by_query", [
            RequestOptions::QUERY => ['refresh' => 'true', 'conflicts' => 'proceed'],
            RequestOptions::JSON => [
                'query' => [
                    'bool' => [
                        'should' => [
                            ['terms' => ['url.keyword' => $urls]],
                            ['terms' => ['additional_urls.keyword' => $urls]],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
            ],
            // A missing index means there is nothing to delete; other errors
            // still throw.
            RequestOptions::HTTP_ERRORS => false,
        ]);

        $status = $response->getStatusCode();
        if ($status === 404) {
            return 0;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Elasticsearch returned HTTP $status: {$response->getBody()}");
        }

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return (int) ($body['deleted'] ?? 0);
    }
}
