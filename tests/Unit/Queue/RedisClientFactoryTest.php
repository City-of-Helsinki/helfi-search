<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\RedisClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Predis\Command\Redis\HGETALL;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Replication\SentinelReplication;

#[CoversClass(RedisClientFactory::class)]
final class RedisClientFactoryTest extends TestCase
{
    public function testOneServer(): void
    {
        $client = RedisClientFactory::create('redis://redis.example:6380', '', '', 'secret', '', '2');

        $connection = $client->getConnection();
        $this->assertInstanceOf(NodeConnectionInterface::class, $connection);
        $parameters = $connection->getParameters();
        $this->assertSame('redis.example', $parameters->host);
        $this->assertSame(6380, $parameters->port);
        $this->assertSame('secret', $parameters->password);
        $this->assertSame('2', (string) $parameters->database);
    }

    public function testSentinel(): void
    {
        $client = RedisClientFactory::create(
            'redis://ignored:6379',
            'tcp://sentinel-0:26379, tcp://sentinel-1:26379',
            'mymaster',
            'secret',
            'sentinel-secret',
            '1',
        );

        $replication = $client->getConnection();
        $this->assertInstanceOf(SentinelReplication::class, $replication);
        $this->assertFalse(
            $replication->getReplicationStrategy()->isReadOperation(new HGETALL()),
            'Reads go to the master too.',
        );
        $sentinel = $replication->getSentinelConnection();
        $this->assertSame('sentinel-0', $sentinel->getParameters()->host);
        $this->assertSame('sentinel-secret', $sentinel->getParameters()->password);
    }

    public function testSentinelNeedsAService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_SENTINEL_SERVICE');
        RedisClientFactory::create('', 'tcp://sentinel-0:26379', '', '', '', '0');
    }
}
