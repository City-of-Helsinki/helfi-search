<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\StderrLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StderrLogger::class)]
final class StderrLoggerTest extends TestCase
{
    public function testFormatAndPlaceholders(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $logger = new StderrLogger($stream);

        $logger->warning('Skipping {url}: {reason}.', ['url' => 'https://www.hel.fi/', 'reason' => new class {
            public function __toString(): string
            {
                return 'unknown site';
            }
        }, 'exception' => new \RuntimeException('not shown')]);
        $logger->info('Deleted {count} document(s), {missing} stays.', ['count' => 3]);

        rewind($stream);
        $lines = explode("\n", trim((string) stream_get_contents($stream)));

        $this->assertCount(2, $lines);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\] \[WARNING\] Skipping https:\/\/www\.hel\.fi\/: unknown site\.$/',
            $lines[0],
        );
        $this->assertStringEndsWith('[INFO] Deleted 3 document(s), {missing} stays.', $lines[1]);
    }
}
