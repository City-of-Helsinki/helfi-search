<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\Action;
use App\Queue\Batch;
use App\Queue\RedisClientFactory;
use App\Queue\RedisQueue;
use App\Tests\RedisTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Against the compose project's Redis, with keys of the test's own.
 */
#[CoversClass(RedisQueue::class)]
#[CoversClass(Batch::class)]
#[UsesClass(RedisClientFactory::class)]
final class RedisQueueTest extends TestCase
{
    use RedisTrait;

    private const int LEASE = 600;

    private function queue(int $maxAttempts = 5, int $retryDelay = 0): RedisQueue
    {
        return new RedisQueue($this->redis(), $this->redisPrefix(), $maxAttempts, $retryDelay);
    }

    private function claim(?RedisQueue $queue = null, int $maxUrls = 100, int $lease = self::LEASE): ?Batch
    {
        return ($queue ?? $this->queue())->claim($maxUrls, 0, $lease);
    }

    /**
     * @return array<string, mixed> The pending change for a URL.
     */
    private function pending(string $url): array
    {
        $json = $this->redis()->hget($this->redisPrefix() . 'pending', $url);
        $this->assertIsString($json, "$url is pending.");
        $change = json_decode($json, true);
        $this->assertIsArray($change);
        /** @var array<string, mixed> $change */
        return $change;
    }

    public function testClaimsTheLatestChangePerUrl(): void
    {
        $queue = $this->queue();
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        $queue->add('https://nuorten.hel.fi/b', Action::Update);
        $queue->add('https://nuorten.hel.fi/a', Action::Delete);

        $batch = $this->claim($queue);

        $this->assertNotNull($batch);
        $this->assertEqualsCanonicalizing(
            ['https://nuorten.hel.fi/a' => Action::Delete, 'https://nuorten.hel.fi/b' => Action::Update],
            $batch->actions,
        );
        $this->assertSame(['https://nuorten.hel.fi/a'], $batch->urls(Action::Delete));
        $this->assertNull($this->claim($queue), 'Nothing left.');
    }

    public function testEmptyQueue(): void
    {
        $this->assertNull($this->claim());
        $this->assertSame(
            ['pending' => 0, 'processing' => 0, 'batches' => 0, 'failed' => 0, 'workers' => []],
            $this->queue()->stats(),
        );
    }

    public function testWaitsForChangesToSettle(): void
    {
        $queue = $this->queue();
        $queue->add('https://nuorten.hel.fi/a', Action::Update);

        $this->assertNull($queue->claim(100, 60, self::LEASE), 'Too new.');
        $this->assertNotNull($queue->claim(100, 0, self::LEASE));
    }

    public function testBatchSize(): void
    {
        $queue = $this->queue();
        foreach (['a', 'b', 'c'] as $path) {
            $queue->add("https://nuorten.hel.fi/$path", Action::Update);
        }

        $first = $this->claim($queue, maxUrls: 2);
        $second = $this->claim($queue, maxUrls: 2);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertCount(2, $first->actions);
        $this->assertCount(1, $second->actions);
        $this->assertSame([], array_intersect_key($first->actions, $second->actions), 'No URL in two batches.');
        $this->assertNotSame($first->id, $second->id);
        $stats = $queue->stats();
        $this->assertSame([0, 3, 2], [$stats['pending'], $stats['processing'], $stats['batches']]);
    }

    public function testUrlBeingProcessedIsNotClaimedAgain(): void
    {
        $queue = $this->queue();
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        $first = $this->claim($queue);
        $this->assertNotNull($first);

        // A newer change while the first batch is processed: it waits for it.
        $queue->add('https://nuorten.hel.fi/a', Action::Delete);
        $queue->add('https://nuorten.hel.fi/b', Action::Update);
        $second = $this->claim($queue);
        $this->assertNotNull($second);
        $this->assertSame(['https://nuorten.hel.fi/b' => Action::Update], $second->actions);

        $queue->complete($first);
        $third = $this->claim($queue);
        $this->assertNotNull($third);
        $this->assertSame(['https://nuorten.hel.fi/a' => Action::Delete], $third->actions, 'The newer change stays.');
    }

    public function testComplete(): void
    {
        $queue = $this->queue();
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        $batch = $this->claim($queue);
        $this->assertNotNull($batch);

        $queue->complete($batch);

        $this->assertNull($this->claim($queue));
        $stats = $queue->stats();
        $this->assertSame([0, 0, 0, 0], [$stats['pending'], $stats['processing'], $stats['batches'], $stats['failed']]);
    }

    public function testReleaseWithoutAnError(): void
    {
        $queue = $this->queue(retryDelay: 3600);
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        $batch = $this->claim($queue);
        $this->assertNotNull($batch);

        $queue->release($batch);

        $this->assertSame(0, $this->pending('https://nuorten.hel.fi/a')['attempts'], 'Not counted as an attempt.');
        $this->assertNotNull($this->claim($queue), 'Claimable right away.');
    }

    public function testFailedChangesAreRetriedLater(): void
    {
        $queue = $this->queue(retryDelay: 3600);
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        $batch = $this->claim($queue);
        $this->assertNotNull($batch);

        $queue->release($batch, 'Elasticsearch is down.');

        $change = $this->pending('https://nuorten.hel.fi/a');
        $this->assertSame(1, $change['attempts']);
        $this->assertSame('Elasticsearch is down.', $change['error']);
        $this->assertNull($this->claim($queue), 'Not before the retry delay.');
    }

    public function testChangesFailAfterTheLastAttempt(): void
    {
        $queue = $this->queue(maxAttempts: 2);
        $queue->add('https://nuorten.hel.fi/a', Action::Delete);

        foreach (['First error', 'Second error'] as $error) {
            $batch = $this->claim($queue);
            $this->assertNotNull($batch, 'Retried right away: no retry delay.');
            $queue->release($batch, $error);
        }

        $this->assertNull($this->claim($queue));
        $failed = $queue->failed();
        $this->assertSame(['https://nuorten.hel.fi/a'], array_keys($failed));
        $this->assertSame('delete', $failed['https://nuorten.hel.fi/a']['action'] ?? null);
        $this->assertSame('Second error', $failed['https://nuorten.hel.fi/a']['error'] ?? null);
        $this->assertSame(1, $queue->stats()['failed']);

        $this->assertSame(1, $queue->retryFailed());
        $this->assertSame([], $queue->failed());
        $batch = $this->claim($queue);
        $this->assertNotNull($batch, 'Queued again.');
        $this->assertSame(['https://nuorten.hel.fi/a' => Action::Delete], $batch->actions);
    }

    public function testReleaseKeepsANewerChange(): void
    {
        $queue = $this->queue(maxAttempts: 1);
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        $batch = $this->claim($queue);
        $this->assertNotNull($batch);
        $queue->add('https://nuorten.hel.fi/a', Action::Delete);

        $queue->release($batch, 'Failed');

        $this->assertSame([], $queue->failed(), 'The newer change replaces the failed one.');
        $change = $this->pending('https://nuorten.hel.fi/a');
        $this->assertSame(['delete', 0], [$change['action'], $change['attempts']]);
    }

    public function testExpiredLeasesAreTakenOver(): void
    {
        $queue = $this->queue();
        $queue->add('https://nuorten.hel.fi/a', Action::Update);
        // A worker that died: its lease ran out right away.
        $lost = $this->claim($queue, lease: 0);
        $this->assertNotNull($lost);

        $batch = $this->claim($queue);

        $this->assertNotNull($batch);
        $this->assertSame(['https://nuorten.hel.fi/a' => Action::Update], $batch->actions);
        $this->assertSame(1, $queue->stats()['batches']);
        $this->assertFalse($queue->renew($lost, self::LEASE), 'The lease is gone.');
        $this->assertTrue($queue->renew($batch, self::LEASE));

        // The first worker finishing late changes nothing.
        $queue->complete($lost);
        $this->assertSame(1, $queue->stats()['processing']);
    }

    public function testWorkers(): void
    {
        $queue = $this->queue();
        $queue->heartbeat('worker-b');
        $queue->heartbeat('worker-a');
        $this->redis()->hset(
            $this->redisPrefix() . 'workers',
            'gone',
            (string) (time() - RedisQueue::WORKER_TIMEOUT - 1),
        );

        $this->assertSame(['worker-a', 'worker-b'], $queue->stats()['workers']);
        $this->assertSame(2, $this->redis()->hlen($this->redisPrefix() . 'workers'), 'Stale workers are removed.');
    }

    public function testPing(): void
    {
        $this->assertTrue($this->queue()->ping());

        $down = RedisClientFactory::create('redis://127.0.0.1:1', '', '', '', '', '0');
        $this->assertFalse((new RedisQueue($down, $this->redisPrefix()))->ping());
    }

    public function testOnceEvery(): void
    {
        $queue = $this->queue();

        $this->assertTrue($queue->onceEvery('clean-up', 60));
        $this->assertFalse($queue->onceEvery('clean-up', 60));
        $this->assertTrue($queue->onceEvery('other', 60));
    }
}
