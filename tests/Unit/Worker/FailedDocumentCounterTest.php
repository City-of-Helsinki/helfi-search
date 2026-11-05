<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Worker\FailedDocumentCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailedDocumentCounter::class)]
final class FailedDocumentCounterTest extends TestCase
{
    private const string SUMMARY = "- Failed\n"
        . "  - Number of documents that failed to index: 3\n"
        . "  - Volume (bytes): 100\n";

    public function testNoFailures(): void
    {
        $counter = new FailedDocumentCounter();
        $counter->read('out', "Crawling...\n  - Number of documents that failed to index: 0\n");

        $this->assertSame(0, $counter->failed());
    }

    public function testWholeSummary(): void
    {
        $counter = new FailedDocumentCounter();
        $counter->read('out', self::SUMMARY);

        $this->assertSame(3, $counter->failed());
    }

    public function testSummarySplitAcrossChunks(): void
    {
        $counter = new FailedDocumentCounter();
        foreach (str_split(self::SUMMARY, 7) as $chunk) {
            $counter->read('out', $chunk);
        }

        $this->assertSame(3, $counter->failed());
    }

    public function testStreamsAreReadSeparately(): void
    {
        // Interleaved stderr output must not break a stdout line apart.
        $counter = new FailedDocumentCounter();
        $counter->read('out', '  - Number of documents that fail');
        $counter->read('err', "a warning\n");
        $counter->read('out', "ed to index: 4\n");

        $this->assertSame(4, $counter->failed());
    }

    public function testLastLineWithoutNewline(): void
    {
        $counter = new FailedDocumentCounter();
        $counter->read('out', 'Number of documents that failed to index: 2');

        $this->assertSame(2, $counter->failed());
    }

    public function testSeveralCrawlSummaries(): void
    {
        $counter = new FailedDocumentCounter();
        $counter->read('out', self::SUMMARY . self::SUMMARY);

        $this->assertSame(6, $counter->failed());
    }

    public function testOverlongLinesDontGrowMemory(): void
    {
        $counter = new FailedDocumentCounter();
        for ($i = 0; $i < 50; $i++) {
            $counter->read('out', str_repeat('x', 100_000));
        }

        // 5 MB of output without a newline, but only a bounded part is kept.
        $unfinished = (new \ReflectionProperty(FailedDocumentCounter::class, 'unfinished'))->getValue($counter);
        $this->assertIsArray($unfinished);
        $this->assertIsString($unfinished['out'] ?? null);
        $this->assertLessThan(70_000, strlen($unfinished['out']));

        $counter->read('out', "\n" . self::SUMMARY);
        $this->assertSame(3, $counter->failed());
    }
}
