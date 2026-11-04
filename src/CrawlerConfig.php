<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Yaml\Yaml;

/**
 * Read access to config/crawler.yml: the crawled sites, the index and the
 * Elasticsearch connection.
 *
 * Shared by the webhook (which sites to accept) and the worker (how to crawl
 * them), so the list of sites lives in one place.
 *
 * The Elasticsearch connection differs per environment, so these environment
 * variables override it when they're set and not empty:
 *
 *   ELASTICSEARCH_HOST      elasticsearch.host, e.g. "https://elastic.example"
 *   ELASTICSEARCH_PORT      elasticsearch.port
 *   ELASTICSEARCH_API_KEY   elasticsearch.api_key
 *   ELASTICSEARCH_USERNAME  elasticsearch.username
 *   ELASTICSEARCH_PASSWORD  elasticsearch.password
 */
final class CrawlerConfig
{
    private const array ENVIRONMENT_OVERRIDES = [
        'ELASTICSEARCH_HOST' => 'host',
        'ELASTICSEARCH_PORT' => 'port',
        'ELASTICSEARCH_API_KEY' => 'api_key',
        'ELASTICSEARCH_USERNAME' => 'username',
        'ELASTICSEARCH_PASSWORD' => 'password',
    ];

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        private readonly array $config,
    ) {
    }

    /**
     * The project's crawler.yml: /app/config/crawler.yml in the images.
     */
    public static function path(): string
    {
        return dirname(__DIR__) . '/config/crawler.yml';
    }

    public static function fromFile(string $path): self
    {
        $config = Yaml::parseFile($path);
        if (!is_array($config) || empty($config['domains'])) {
            throw new \RuntimeException("No domains configured in $path.");
        }

        $elasticsearch = is_array($config['elasticsearch'] ?? null) ? $config['elasticsearch'] : [];
        foreach (self::ENVIRONMENT_OVERRIDES as $variable => $key) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                $elasticsearch[$key] = $key === 'port' ? (int) $value : $value;
            }
        }
        $config['elasticsearch'] = $elasticsearch;

        return new self($config);
    }

    /**
     * The whole configuration, with the environment overrides applied.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * The crawled sites' URLs, e.g. "https://nuorten.hel.fi".
     *
     * @return list<string>
     */
    public function sites(): array
    {
        return array_values(array_map(fn (array $domain): string => (string) $domain['url'], $this->config['domains']));
    }

    /**
     * Returns the configured domain (e.g. "https://nuorten.hel.fi") that the
     * URL belongs to, or NULL if the crawler doesn't crawl that site.
     */
    public function domainFor(string $url): ?string
    {
        $origin = Site::origin($url);

        foreach ($this->config['domains'] as $domain) {
            if (Site::origin((string) $domain['url']) === $origin) {
                return (string) $domain['url'];
            }
        }

        return null;
    }

    public function elasticsearchUrl(): string
    {
        $es = $this->config['elasticsearch'];
        $host = rtrim((string) ($es['host'] ?? 'http://localhost'), '/');
        $port = (int) ($es['port'] ?? 9200);

        return "$host:$port";
    }

    /**
     * The Elasticsearch credentials, if any: an API key, or a username and
     * password.
     *
     * @return array{api_key?: string, username?: string, password?: string}
     */
    public function elasticsearchCredentials(): array
    {
        $es = $this->config['elasticsearch'];
        if (!empty($es['api_key'])) {
            return ['api_key' => (string) $es['api_key']];
        }
        if (!empty($es['username'])) {
            return ['username' => (string) $es['username'], 'password' => (string) ($es['password'] ?? '')];
        }

        return [];
    }

    public function index(): string
    {
        return (string) ($this->config['output_index'] ?? throw new \RuntimeException('output_index is not set.'));
    }
}
