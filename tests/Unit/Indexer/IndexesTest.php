<?php

declare(strict_types=1);

namespace App\Tests\Unit\Indexer;

use App\Indexer\Indexes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Indexes::class)]
final class IndexesTest extends TestCase
{
    public function testCrawlIndexes(): void
    {
        $indexes = new Indexes('hel-fi', 'hel-fi-embedding-cache', 'hel-fi-pages');
        $before = time();
        $index = $indexes->newCrawlIndex();

        $this->assertMatchesRegularExpression('/^hel-fi-pages-\d{8}-\d{6}-[0-9a-f]{8}$/', $index);
        $this->assertNotSame($index, $indexes->newCrawlIndex());
        $this->assertSame('hel-fi-pages-*', $indexes->crawlIndexPattern());
        $this->assertEqualsWithDelta($before, $indexes->crawlIndexCreated($index), 1);
        $this->assertSame(
            gmmktime(12, 30, 0, 11, 17, 2026),
            $indexes->crawlIndexCreated('hel-fi-pages-20261117-123000-0123abcd'),
        );
        $this->assertNull($indexes->crawlIndexCreated('hel-fi-pages-old'));
        $this->assertNull($indexes->crawlIndexCreated('hel-fi-v1'));
    }
}
