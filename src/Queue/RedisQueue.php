<?php

declare(strict_types=1);

namespace App\Queue;

use Predis\ClientInterface;
use Predis\Response\Status;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The queue of content changes, in Redis. The webhook adds changes; workers
 * claim them in batches. Any number of each can run at once.
 *
 * Keys, under the prefix (REDIS_PREFIX):
 *
 *   pending   hash  URL => {"action", "at", "attempts", "not_before"?, "error"?}
 *   inflight  hash  URL => the same, plus "batch": URLs in a claimed batch
 *   batches   zset  batch ID, scored by when its lease expires
 *   failed    hash  URL => the change, with "error" and "failed_at"
 *   workers   hash  worker name => when it was last seen
 *
 * Only the latest change per URL is kept: a new one replaces it in
 * "pending". A URL is in at most one batch at a time ("inflight"), so a
 * change and a later one for the same URL can't be applied out of order by
 * two workers. A batch whose worker stops renewing its lease (it crashed or
 * was killed) goes back to "pending" on the next claim.
 *
 * Every change of state is a Lua script, so it's atomic. Times are Redis's
 * own clock (TIME), so the pods' clocks don't matter.
 */
final class RedisQueue
{
    /** A worker not seen for this long is no longer listed. */
    public const int WORKER_TIMEOUT = 900;

    private const string RELEASE_FUNCTION = <<<'LUA'
        -- Returns a batch's URLs to "pending". With an error, it counts as a
        -- failed attempt: the URL is retried after a delay that doubles each
        -- time, and moves to "failed" after the last attempt. A newer change
        -- for the URL, queued meanwhile, takes precedence either way.
        local function release(pending, inflight, batches, failed, batch, now, err, maxAttempts, retryDelay)
          local entries = redis.call('HGETALL', inflight)
          local count = 0
          for i = 1, #entries, 2 do
            local url = entries[i]
            local change = cjson.decode(entries[i + 1])
            if change.batch == batch then
              redis.call('HDEL', inflight, url)
              count = count + 1
              change.batch = nil
              if redis.call('HEXISTS', pending, url) == 0 then
                if err ~= '' then
                  change.attempts = (change.attempts or 0) + 1
                  change.error = err
                  change.not_before = now + retryDelay * 2 ^ (change.attempts - 1)
                end
                if change.attempts >= maxAttempts then
                  change.not_before = nil
                  change.failed_at = now
                  redis.call('HSET', failed, url, cjson.encode(change))
                else
                  redis.call('HSET', pending, url, cjson.encode(change))
                end
              end
            end
          end
          redis.call('ZREM', batches, batch)
          return count
        end
        LUA;

    /** KEYS: pending. ARGV: url, action. */
    private const string ADD = <<<'LUA'
        local now = tonumber(redis.call('TIME')[1])
        redis.call('HSET', KEYS[1], ARGV[1], cjson.encode({action = ARGV[2], at = now, attempts = 0}))
        LUA;

    /**
     * KEYS: pending, inflight, batches, failed.
     * ARGV: batch, max URLs, settle seconds, lease seconds, max attempts,
     * retry delay.
     */
    private const string CLAIM = <<<'LUA'
        local now = tonumber(redis.call('TIME')[1])
        local batch, maxUrls, settle, lease = ARGV[1], tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4])

        -- Batches whose worker stopped.
        for _, expired in ipairs(redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', now)) do
          release(KEYS[1], KEYS[2], KEYS[3], KEYS[4], expired, now,
            'The worker stopped before finishing the batch.', tonumber(ARGV[5]), tonumber(ARGV[6]))
        end

        local entries = redis.call('HGETALL', KEYS[1])
        local claimed = {}
        for i = 1, #entries, 2 do
          if #claimed / 2 >= maxUrls then
            break
          end
          local url = entries[i]
          local change = cjson.decode(entries[i + 1])
          if change.at <= now - settle and (change.not_before or 0) <= now
              and redis.call('HEXISTS', KEYS[2], url) == 0 then
            change.batch = batch
            redis.call('HSET', KEYS[2], url, cjson.encode(change))
            redis.call('HDEL', KEYS[1], url)
            table.insert(claimed, url)
            table.insert(claimed, change.action)
          end
        end

        if #claimed > 0 then
          redis.call('ZADD', KEYS[3], now + lease, batch)
        end
        return claimed
        LUA;

    /** KEYS: batches. ARGV: batch, lease seconds. */
    private const string RENEW = <<<'LUA'
        if not redis.call('ZSCORE', KEYS[1], ARGV[1]) then
          return 0
        end
        local now = tonumber(redis.call('TIME')[1])
        redis.call('ZADD', KEYS[1], 'XX', now + tonumber(ARGV[2]), ARGV[1])
        return 1
        LUA;

    /** KEYS: inflight, batches. ARGV: batch. */
    private const string COMPLETE = <<<'LUA'
        local entries = redis.call('HGETALL', KEYS[1])
        for i = 1, #entries, 2 do
          if cjson.decode(entries[i + 1]).batch == ARGV[1] then
            redis.call('HDEL', KEYS[1], entries[i])
          end
        end
        redis.call('ZREM', KEYS[2], ARGV[1])
        LUA;

    /**
     * KEYS: pending, inflight, batches, failed.
     * ARGV: batch, error ('' if none), max attempts, retry delay.
     */
    private const string RELEASE = <<<'LUA'
        local now = tonumber(redis.call('TIME')[1])
        return release(KEYS[1], KEYS[2], KEYS[3], KEYS[4], ARGV[1], now, ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4]))
        LUA;

    /** KEYS: failed, pending. Moves every failed change back to "pending". */
    private const string RETRY_FAILED = <<<'LUA'
        local entries = redis.call('HGETALL', KEYS[1])
        local now = tonumber(redis.call('TIME')[1])
        for i = 1, #entries, 2 do
          local change = cjson.decode(entries[i + 1])
          redis.call('HSETNX', KEYS[2], entries[i], cjson.encode({action = change.action, at = now, attempts = 0}))
        end
        redis.call('DEL', KEYS[1])
        return #entries / 2
        LUA;

    /**
     * @param string $prefix Prepended to every key.
     * @param int $maxAttempts Failed attempts before a change moves to
     *   "failed".
     * @param int $retryDelay Seconds before the first retry of a failed
     *   change; doubled for each further attempt.
     */
    public function __construct(
        private readonly ClientInterface $redis,
        #[Autowire(env: 'REDIS_PREFIX')]
        private readonly string $prefix,
        private readonly int $maxAttempts = 5,
        private readonly int $retryDelay = 60,
    ) {
    }

    /**
     * Queues a change, replacing any change queued earlier for the URL.
     */
    public function add(string $url, Action $action): void
    {
        $this->run(self::ADD, ['pending'], [$url, $action->value]);
    }

    /**
     * Claims up to $maxUrls changes. Also returns the batches of workers
     * whose lease expired to the queue.
     *
     * @param int $settleSeconds Only changes at least this old: a site often
     *   sends several in a row for one save.
     * @param int $leaseSeconds How long the batch is ours without renew().
     * @return Batch|null NULL if there's nothing to claim.
     */
    public function claim(int $maxUrls, int $settleSeconds, int $leaseSeconds): ?Batch
    {
        $id = gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(4));
        $claimed = $this->run(
            self::RELEASE_FUNCTION . "\n" . self::CLAIM,
            ['pending', 'inflight', 'batches', 'failed'],
            [$id, $maxUrls, $settleSeconds, $leaseSeconds, $this->maxAttempts, $this->retryDelay],
        );

        $actions = [];
        $flat = is_array($claimed) ? array_values($claimed) : [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $action = is_string($flat[$i + 1]) ? Action::tryFrom($flat[$i + 1]) : null;
            if (is_string($flat[$i]) && $action !== null) {
                $actions[$flat[$i]] = $action;
            }
        }

        return $actions === [] ? null : new Batch($id, $actions);
    }

    /**
     * Extends the batch's lease.
     *
     * @return bool FALSE if the lease had expired and the batch was returned
     *   to the queue: another worker may be processing it now.
     */
    public function renew(Batch $batch, int $leaseSeconds): bool
    {
        return $this->run(self::RENEW, ['batches'], [$batch->id, $leaseSeconds]) === 1;
    }

    /**
     * Removes a processed batch. Changes queued meanwhile for its URLs stay.
     */
    public function complete(Batch $batch): void
    {
        $this->run(self::COMPLETE, ['inflight', 'batches'], [$batch->id]);
    }

    /**
     * Returns a batch to the queue.
     *
     * @param string|null $error Why it failed, which counts as a failed
     *   attempt; NULL if it wasn't the changes' fault, e.g. the worker is
     *   shutting down.
     */
    public function release(Batch $batch, ?string $error = null): void
    {
        $this->run(
            self::RELEASE_FUNCTION . "\n" . self::RELEASE,
            ['pending', 'inflight', 'batches', 'failed'],
            [$batch->id, $error ?? '', $this->maxAttempts, $this->retryDelay],
        );
    }

    /**
     * Queues the failed changes again, with their attempts reset.
     *
     * @return int The number of changes.
     */
    public function retryFailed(): int
    {
        $count = $this->run(self::RETRY_FAILED, ['failed', 'pending']);

        return is_int($count) ? $count : 0;
    }

    /**
     * The failed changes.
     *
     * @return array<string, array<mixed>> By URL.
     */
    public function failed(): array
    {
        $failed = [];
        foreach ($this->redis->hgetall($this->key('failed')) as $url => $json) {
            $change = is_string($json) ? json_decode($json, true) : null;
            $failed[(string) $url] = is_array($change) ? $change : [];
        }

        return $failed;
    }

    /**
     * Records that a worker is alive, for stats().
     */
    public function heartbeat(string $worker): void
    {
        $this->redis->hset($this->key('workers'), $worker, (string) time());
    }

    /**
     * @return array{pending: int, processing: int, batches: int, failed: int, workers: list<string>}
     *   "processing" counts the URLs in claimed batches; "workers" lists the
     *   workers seen recently.
     */
    public function stats(): array
    {
        $workers = [];
        $stale = [];
        foreach ($this->redis->hgetall($this->key('workers')) as $name => $seen) {
            if (is_numeric($seen) && (int) $seen >= time() - self::WORKER_TIMEOUT) {
                $workers[] = (string) $name;
            } else {
                $stale[] = (string) $name;
            }
        }
        if ($stale !== []) {
            $this->redis->hdel($this->key('workers'), $stale);
        }
        sort($workers);

        return [
            'pending' => $this->redis->hlen($this->key('pending')),
            'processing' => $this->redis->hlen($this->key('inflight')),
            'batches' => $this->redis->zcard($this->key('batches')),
            'failed' => $this->redis->hlen($this->key('failed')),
            'workers' => $workers,
        ];
    }

    /**
     * Whether Redis answers.
     */
    public function ping(): bool
    {
        try {
            $response = $this->redis->ping();

            return ($response instanceof Status ? $response->getPayload() : $response) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns TRUE at most once per $seconds, across all processes: for
     * maintenance that only one of many processes should do.
     */
    public function onceEvery(string $name, int $seconds): bool
    {
        return $this->redis->set($this->key("once:$name"), (string) time(), 'EX', $seconds, 'NX') !== null;
    }

    /**
     * @param list<string> $keys Without the prefix.
     * @param list<string|int> $arguments
     */
    private function run(string $script, array $keys, array $arguments = []): mixed
    {
        return $this->redis->eval(
            $script,
            count($keys),
            ...array_map($this->key(...), $keys),
            ...array_map('strval', $arguments),
        );
    }

    private function key(string $name): string
    {
        return $this->prefix . $name;
    }
}
