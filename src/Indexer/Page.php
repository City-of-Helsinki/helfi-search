<?php

declare(strict_types=1);

namespace App\Indexer;

/**
 * A crawled page, as the crawler stored it.
 */
final readonly class Page
{
    /**
     * @param string $id The crawler's document ID: a hash of the (canonical)
     *   URL. The search index uses the same ID.
     * @param list<string> $additionalUrls Other URLs with the same canonical
     *   URL.
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $additionalUrls,
        public string $html,
        public ?string $lastCrawledAt,
    ) {
    }

    /**
     * @param array<mixed> $source The crawler's document.
     *
     * @throws \UnexpectedValueException If it has no URL or HTML.
     */
    public static function fromSource(string $id, array $source): self
    {
        $url = $source['url'] ?? null;
        $html = $source['full_html'] ?? null;
        if (!is_string($url) || !is_string($html)) {
            throw new \UnexpectedValueException("Crawled document $id has no url or full_html.");
        }

        $additionalUrls = is_array($source['additional_urls'] ?? null)
            ? array_values(array_filter($source['additional_urls'], 'is_string'))
            : [];
        $lastCrawledAt = $source['last_crawled_at'] ?? null;

        return new self($id, $url, $additionalUrls, $html, is_string($lastCrawledAt) ? $lastCrawledAt : null);
    }
}
