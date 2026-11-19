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

    /** How often a crawl calls its tick. */
    public int $ticks = 0;

    public int $cleanUps = 0;

    public function crawl(CrawlerConfig $config, array $crawlConfig, bool $sweep, ?\Closure $tick = null): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->crawls[] = [$crawlConfig, $sweep];
        for ($i = 0; $i < $this->ticks && $tick !== null; $i++) {
            $tick();
        }
    }

    public function delete(CrawlerConfig $config, array $urls): int
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->deletes[] = $urls;

        return count($urls);
    }

    public function cleanUp(CrawlerConfig $config): void
    {
        $this->cleanUps++;
    }
}
