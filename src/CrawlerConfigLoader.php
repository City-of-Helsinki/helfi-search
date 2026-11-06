<?php

declare(strict_types=1);

namespace App;

/**
 * Reads config/crawler.yml (the app.crawler_config parameter).
 *
 * The file is read on every load(), so edits apply to the next request or
 * worker run without a restart.
 */
final readonly class CrawlerConfigLoader
{
    public function __construct(
        public string $path,
    ) {
    }

    /**
     * @throws \RuntimeException If the file can't be read or isn't a valid
     *   crawler config.
     */
    public function load(): CrawlerConfig
    {
        return CrawlerConfig::fromFile($this->path);
    }
}
