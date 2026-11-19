<?php

declare(strict_types=1);

namespace App\Tests;

use App\Queue\RedisClientFactory;
use PHPUnit\Framework\Attributes\After;
use Predis\ClientInterface;

/**
 * The compose project's Redis (REDIS_URL), with a key prefix of the test's
 * own: tests don't see each other's keys, or the workers'. The keys are
 * deleted afterwards.
 */
trait RedisTrait
{
    private ?ClientInterface $redis = null;
    private ?string $redisPrefix = null;

    protected function redis(): ClientInterface
    {
        if ($this->redis === null) {
            $url = getenv('REDIS_URL');
            $this->redis = RedisClientFactory::create(
                is_string($url) && $url !== '' ? $url : 'redis://redis:6379',
                '',
                '',
                '',
                '',
                '0',
            );
        }

        return $this->redis;
    }

    protected function redisPrefix(): string
    {
        return $this->redisPrefix ??= 'helfi-search-test-' . bin2hex(random_bytes(6)) . ':';
    }

    #[After]
    protected function deleteRedisKeys(): void
    {
        if ($this->redisPrefix !== null) {
            /** @var list<string> $keys */
            $keys = $this->redis()->keys($this->redisPrefix . '*');
            if ($keys !== []) {
                $this->redis()->del($keys);
            }
            $this->redisPrefix = null;
        }
    }
}
