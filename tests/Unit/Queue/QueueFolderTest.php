<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\QueueFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueueFolder::class)]
final class QueueFolderTest extends TestCase
{
    public function testLayout(): void
    {
        $folder = new QueueFolder('/data/queue/');

        $this->assertSame('/data/queue/incoming', $folder->incoming);
        $this->assertSame('/data/queue/processing', $folder->processing);
        $this->assertSame('/data/queue/failed', $folder->failed);
        $this->assertSame('/data/queue/worker.lock', $folder->lockFile);
    }

    public function testFileNamesSortInArrivalOrder(): void
    {
        $names = [];
        for ($i = 0; $i < 20; $i++) {
            $names[] = QueueFolder::newFileName();
            usleep(10);
        }

        $this->assertMatchesRegularExpression('/^\d{16}-[0-9a-f]{8}\.json$/', $names[0]);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($names, $sorted);
        $this->assertCount(20, array_unique($names));
        $this->assertTrue(fnmatch(QueueFolder::REQUEST_FILES, $names[0]));
    }
}
