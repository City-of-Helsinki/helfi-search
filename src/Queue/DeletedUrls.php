<?php

declare(strict_types=1);

namespace App\Queue;

use Predis\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * When the worker deleted pages, by URL (the "deleted" hash in Redis).
 *
 * A crawl that fetched a page before it was deleted must not index it again:
 * the indexer checks this. Entries are kept for a couple of days, longer than
 * any crawl.
 */
final readonly class DeletedUrls
{
    /** KEYS: deleted. ARGV: URLs. */
    private const string RECORD = <<<'LUA'
        local now = redis.call('TIME')[1]
        for _, url in ipairs(ARGV) do
          redis.call('HSET', KEYS[1], url, now)
        end
        LUA;

    /** KEYS: deleted. ARGV: max age in seconds. */
    private const string PRUNE = <<<'LUA'
        local before = tonumber(redis.call('TIME')[1]) - tonumber(ARGV[1])
        local entries = redis.call('HGETALL', KEYS[1])
        local removed = 0
        for i = 1, #entries, 2 do
          if tonumber(entries[i + 1]) < before then
            redis.call('HDEL', KEYS[1], entries[i])
            removed = removed + 1
          end
        end
        return removed
        LUA;

    public function __construct(
        private ClientInterface $redis,
        #[Autowire(env: 'REDIS_PREFIX')]
        private string $prefix,
    ) {
    }

    /**
     * Records that the URLs were deleted now.
     *
     * @param list<string> $urls
     */
    public function record(array $urls): void
    {
        if ($urls !== []) {
            $this->redis->eval(self::RECORD, 1, $this->key(), ...$urls);
        }
    }

    /**
     * @param list<string> $urls
     * @return array<string, int> When the URLs were deleted, as Unix times.
     *   URLs that weren't are left out.
     */
    public function deletedAt(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ($urls === []) {
            return [];
        }

        $times = [];
        foreach ($this->redis->hmget($this->key(), $urls) as $i => $time) {
            if (is_numeric($time)) {
                $times[$urls[$i]] = (int) $time;
            }
        }

        return $times;
    }

    /**
     * Forgets deletions older than $maxAge seconds.
     *
     * @return int The number of forgotten URLs.
     */
    public function prune(int $maxAge): int
    {
        $removed = $this->redis->eval(self::PRUNE, 1, $this->key(), (string) $maxAge);

        return is_int($removed) ? $removed : 0;
    }

    private function key(): string
    {
        return $this->prefix . 'deleted';
    }
}
