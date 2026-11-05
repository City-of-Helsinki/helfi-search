<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\Action;
use App\Queue\QueueFolder;
use App\Queue\QueueWriter;
use App\Queue\Request;
use App\Queue\Spool;
use App\Tests\Unit\CollectingLogger;
use App\Tests\Unit\TemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The two sides of the queue folder: the webhook writes, the worker claims.
 */
#[CoversClass(QueueWriter::class)]
#[CoversClass(Spool::class)]
#[UsesClass(QueueFolder::class)]
#[UsesClass(Request::class)]
final class QueueTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private QueueFolder $folder;
    private QueueWriter $writer;
    private CollectingLogger $logger;
    private int $umask;

    protected function setUp(): void
    {
        // As in the images: the webhook's base image and bin/worker set it.
        $this->umask = umask(0002);
        $this->folder = new QueueFolder($this->temporaryDirectory() . '/var/queue');
        $this->writer = new QueueWriter($this->folder);
        $this->logger = new CollectingLogger();
    }

    protected function tearDown(): void
    {
        umask($this->umask);
    }

    private function add(string $url, Action $action = Action::Update): void
    {
        $this->writer->add(Request::now($url, $action));
        // File names have microsecond precision; keep the order unambiguous.
        usleep(10);
    }

    public function testWriterCreatesTheFolderAndCountsRequests(): void
    {
        $this->assertSame(0, $this->writer->pendingCount());

        $this->add('https://nuorten.hel.fi/a/');
        $this->add('https://nuorten.hel.fi/a/');

        $this->assertSame(2, $this->writer->pendingCount(), 'Every request counts.');
        $files = glob($this->folder->incoming . '/*') ?: [];
        $this->assertCount(2, $files, 'No temporary files are left behind.');
        $this->assertSame(0664, fileperms($files[0]) & 0777, 'Files are group-writable (umask 0002).');
    }

    public function testWriterIsWritable(): void
    {
        $this->assertTrue($this->writer->isWritable(), 'Missing folders are created with the first request.');

        chmod($this->temporaryDirectory(), 0555);
        $this->assertFalse($this->writer->isWritable());
    }

    public function testClaimTakesTheLatestRequestPerUrl(): void
    {
        $spool = new Spool($this->folder, $this->logger);
        $this->add('https://nuorten.hel.fi/a/');
        $this->add('https://nuorten.hel.fi/b/', Action::Delete);
        $this->add('https://nuorten.hel.fi/a/', Action::Delete);
        $this->add('https://nuorten.hel.fi/b/');

        [$batch, $actions] = $spool->claim();

        $this->assertSame(
            ['https://nuorten.hel.fi/a/' => Action::Delete, 'https://nuorten.hel.fi/b/' => Action::Update],
            $actions,
        );
        $this->assertSame(0, $this->writer->pendingCount());
        $this->assertCount(4, glob($this->folder->processing . "/$batch/*.json") ?: []);
    }

    public function testRequestsDuringABatchWaitForTheNextOne(): void
    {
        $spool = new Spool($this->folder, $this->logger);
        $this->add('https://nuorten.hel.fi/a/');
        [$batch] = $spool->claim();

        $this->add('https://nuorten.hel.fi/b/');
        $spool->complete($batch);

        $this->assertSame(1, $this->writer->pendingCount());
        $this->assertDirectoryDoesNotExist($this->folder->processing . "/$batch");
        [, $actions] = $spool->claim();
        $this->assertSame(['https://nuorten.hel.fi/b/'], array_keys($actions));
    }

    public function testEmptyQueue(): void
    {
        [$batch, $actions] = (new Spool($this->folder, $this->logger))->claim();

        $this->assertSame([], $actions);
        $this->assertDirectoryDoesNotExist($this->folder->processing . "/$batch");
    }

    public function testReleasedRequestsKeepTheirOrder(): void
    {
        $spool = new Spool($this->folder, $this->logger);
        $this->add('https://nuorten.hel.fi/a/');
        [$batch] = $spool->claim();

        // A newer request for the same URL arrives while the batch fails.
        $this->add('https://nuorten.hel.fi/a/', Action::Delete);
        $spool->release($batch);

        $this->assertSame(2, $this->writer->pendingCount());
        [, $actions] = $spool->claim();
        $this->assertSame(['https://nuorten.hel.fi/a/' => Action::Delete], $actions, 'The newer request still wins.');
    }

    public function testUnreadableRequestsGoToFailed(): void
    {
        $spool = new Spool($this->folder, $this->logger);
        file_put_contents($this->folder->incoming . '/0000000000000001-broken.json', '{bad');
        $this->add('https://nuorten.hel.fi/a/');

        [, $actions] = $spool->claim();

        $this->assertSame(['https://nuorten.hel.fi/a/'], array_keys($actions));
        $this->assertFileExists($this->folder->failed . '/0000000000000001-broken.json');
        $this->assertSame(
            ['Moved unreadable request 0000000000000001-broken.json to failed/.'],
            $this->logger->messages('warning'),
        );
    }

    public function testFilesBeingWrittenAreIgnored(): void
    {
        $spool = new Spool($this->folder, $this->logger);
        file_put_contents(
            $this->folder->incoming . '/0000000000000001-x.json7f3a',
            '{"url":"https://nuorten.hel.fi/","action":"update"}',
        );

        [, $actions] = $spool->claim();

        $this->assertSame([], $actions);
    }

    public function testOnlyOneWorkerHoldsTheLock(): void
    {
        $first = new Spool($this->folder, $this->logger);
        $second = new Spool($this->folder, $this->logger);

        $this->assertTrue($first->lock());
        $this->assertTrue($first->lock(), 'Locking again is a no-op.');
        $this->assertFalse($second->lock());
        $this->assertSame([], $this->logger->records, 'Locks work here, so nothing is logged.');
    }

    public function testReleaseAllReturnsBatchesOfAWorkerThatDied(): void
    {
        $dead = new Spool($this->folder, $this->logger);
        $this->add('https://nuorten.hel.fi/a/');
        $this->add('https://nuorten.hel.fi/b/');
        $dead->claim();
        unset($dead);

        $spool = new Spool($this->folder, $this->logger);
        $this->assertTrue($spool->lock());
        $this->assertSame(2, $spool->releaseAll());
        $this->assertSame(2, $this->writer->pendingCount());
        $this->assertSame([], glob($this->folder->processing . '/*') ?: []);
    }

    public function testReleaseAllRequiresTheLock(): void
    {
        $this->expectException(\LogicException::class);
        (new Spool($this->folder, $this->logger))->releaseAll();
    }
}
