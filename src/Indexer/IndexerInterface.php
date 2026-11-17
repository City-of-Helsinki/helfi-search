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
     * @param array<string, mixed> $crawlConfig A complete crawler config;
     *   output_index must be the config's.
     * @param bool $sweep Whether to delete what the crawl didn't find: only
     *   for a crawl of every page of every site.
     *
     * @throws \RuntimeException If the crawl or any page fails. The pages
     *   that didn't fail are processed.
     */
    public function crawl(CrawlerConfig $config, array $crawlConfig, bool $sweep): void;

    /**
     * Deletes the pages with the given URLs, from the crawler's index and
     * the search index.
     *
     * @param list<string> $urls
     * @return int The number of deleted search documents.
     */
    public function delete(CrawlerConfig $config, array $urls): int;
}
