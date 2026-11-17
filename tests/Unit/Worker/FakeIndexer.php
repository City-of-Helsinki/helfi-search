<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\CrawlerConfig;
use App\Indexer\IndexerInterface;

/**
 * Records what it's asked to do, for tests of its callers.
 */
final class FakeIndexer implements IndexerInterface
{
    /** @var list<array{array<string, mixed>, bool}> Each crawl's config and sweep. */
    public array $crawls = [];

    /** @var list<list<string>> Each delete's URLs. */
    public array $deletes = [];

    /** @var \RuntimeException|null Thrown by every call, when set. */
    public ?\RuntimeException $failure = null;

    public function crawl(CrawlerConfig $config, array $crawlConfig, bool $sweep): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->crawls[] = [$crawlConfig, $sweep];
    }

    public function delete(CrawlerConfig $config, array $urls): int
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->deletes[] = $urls;

        return count($urls);
    }
}
