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
     * @param array<string, mixed> $config The whole file, with the overrides.
     * @param non-empty-array<string, array<string, mixed>> $domains Each domain's settings, by site URL.
     * @param array<string, mixed> $elasticsearch The connection, with the overrides.
     */
    private function __construct(
        private readonly array $config,
        private readonly array $domains,
        private readonly array $elasticsearch,
    ) {
    }

    /**
     * The project's crawler.yml: /app/config/crawler.yml in the images.
     */
    public static function path(): string
    {
        return dirname(__DIR__) . '/config/crawler.yml';
    }

    /**
     * @throws \RuntimeException If the file isn't a valid crawler config.
     */
    public static function fromFile(string $path): self
    {
        $config = Yaml::parseFile($path);
        if (!is_array($config) || !is_array($config['domains'] ?? null) || $config['domains'] === []) {
            throw new \RuntimeException("No domains configured in $path.");
        }

        $domains = [];
        foreach ($config['domains'] as $domain) {
            $url = is_array($domain) ? ($domain['url'] ?? null) : null;
            if (!is_string($url) || $url === '') {
                throw new \RuntimeException("Every domain in $path needs a url.");
            }
            /** @var array<string, mixed> $domain YAML mappings have string keys. */
            $domains[$url] = $domain;
        }

        $elasticsearch = $config['elasticsearch'] ?? [];
        if (!is_array($elasticsearch)) {
            throw new \RuntimeException("elasticsearch in $path must be a mapping.");
        }
        foreach (self::ENVIRONMENT_OVERRIDES as $variable => $key) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                $elasticsearch[$key] = $key === 'port' ? (int) $value : $value;
            }
        }
        /** @var array<string, mixed> $elasticsearch */
        $config['elasticsearch'] = $elasticsearch;
        /** @var array<string, mixed> $config */

        return new self($config, $domains, $elasticsearch);
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
        return array_keys($this->domains);
    }

    /**
     * Each domain's settings (url, exclude_tags, extraction rules), by site
     * URL.
     *
     * @return non-empty-array<string, array<string, mixed>>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    /**
     * Returns the configured domain (e.g. "https://nuorten.hel.fi") that the
     * URL belongs to, or NULL if the crawler doesn't crawl that site.
     */
    public function domainFor(string $url): ?string
    {
        $origin = Site::origin($url);

        foreach (array_keys($this->domains) as $site) {
            if (Site::origin($site) === $origin) {
                return $site;
            }
        }

        return null;
    }

    /**
     * @throws \RuntimeException If the host or port is invalid.
     */
    public function elasticsearchUrl(): string
    {
        $host = $this->elasticsearch['host'] ?? 'http://localhost';
        $port = $this->elasticsearch['port'] ?? 9200;
        if (!is_string($host) || !(is_int($port) || (is_string($port) && ctype_digit($port)))) {
            throw new \RuntimeException('The Elasticsearch host or port is invalid.');
        }

        return rtrim($host, '/') . ":$port";
    }

    /**
     * The Elasticsearch credentials, if any: an API key, or a username and
     * password.
     *
     * @return array{api_key?: string, username?: string, password?: string}
     */
    public function elasticsearchCredentials(): array
    {
        $apiKey = $this->elasticsearchSetting('api_key');
        if ($apiKey !== '') {
            return ['api_key' => $apiKey];
        }
        $username = $this->elasticsearchSetting('username');
        if ($username !== '') {
            return ['username' => $username, 'password' => $this->elasticsearchSetting('password')];
        }

        return [];
    }

    /**
     * @throws \RuntimeException If output_index is missing.
     */
    public function index(): string
    {
        $index = $this->config['output_index'] ?? null;
        if (!is_string($index) || $index === '') {
            throw new \RuntimeException('output_index is not set.');
        }

        return $index;
    }

    /**
     * A setting from the elasticsearch section, or '' if it's not set.
     */
    private function elasticsearchSetting(string $key): string
    {
        $value = $this->elasticsearch[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
