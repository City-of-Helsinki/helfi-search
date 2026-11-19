<?php

declare(strict_types=1);

namespace App\Indexer;

use App\CrawlerConfig;

/**
 * Crawls pages into the search index, and removes them.
 */
interface IndexerInterface
{
    /**
     * Runs the crawler with the given config, then processes every page it
     * crawled into the search index.
     *
     * @param array<string, mixed> $crawlConfig A complete crawler config.
     *   Its output_index is replaced with an index of this crawl's own.
     * @param bool $sweep Whether to delete what the crawl didn't find on its
     *   sites: only for a crawl of every page of those sites.
     * @param (\Closure(): void)|null $tick Called regularly while working. An
     *   exception it throws stops the crawl, and is rethrown.
     *
     * @throws \RuntimeException If the crawl or any page fails. The pages
     *   that didn't fail are processed.
     */
    public function crawl(CrawlerConfig $config, array $crawlConfig, bool $sweep, ?\Closure $tick = null): void;

    /**
     * Deletes the pages with the given URLs from the search index, and
     * records the deletion, so a crawl that fetched them earlier doesn't add
     * them back.
     *
     * @param list<string> $urls
     * @return int The number of deleted documents.
     */
    public function delete(CrawlerConfig $config, array $urls): int;

    /**
     * Cleans up after crawls: vectors no page uses, old deletion records and
     * crawl indexes left by crawls that crashed.
     */
    public function cleanUp(CrawlerConfig $config): void;
}
