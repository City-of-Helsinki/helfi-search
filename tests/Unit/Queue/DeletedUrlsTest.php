<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\DeletedUrls;
use App\Queue\RedisClientFactory;
use App\Tests\RedisTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeletedUrls::class)]
#[UsesClass(RedisClientFactory::class)]
final class DeletedUrlsTest extends TestCase
{
    use RedisTrait;

    public function testRecordAndPrune(): void
    {
        $deleted = new DeletedUrls($this->redis(), $this->redisPrefix());
        $before = time();
        $deleted->record(['https://nuorten.hel.fi/a', 'https://nuorten.hel.fi/b']);
        $deleted->record([]);

        $times = $deleted->deletedAt([
            'https://nuorten.hel.fi/a',
            'https://nuorten.hel.fi/c',
            'https://nuorten.hel.fi/a',
        ]);
        $this->assertSame(['https://nuorten.hel.fi/a'], array_keys($times));
        $this->assertEqualsWithDelta($before, $times['https://nuorten.hel.fi/a'], 2);
        $this->assertSame([], $deleted->deletedAt([]));

        $this->assertSame(0, $deleted->prune(3600), 'Recent deletions are kept.');
        $this->assertSame(2, $deleted->prune(-10));
        $this->assertSame([], $deleted->deletedAt(['https://nuorten.hel.fi/a']));
    }
}
