<?php

declare(strict_types=1);

namespace App\Queue;

use Predis\Client;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\Parameters;
use Predis\Connection\Replication\SentinelReplication;
use Predis\Replication\ReplicationStrategy;

/**
 * Connects to Redis: one server, or the master of a Sentinel setup.
 *
 * Configured with environment variables (see config/services.yaml):
 *
 *   REDIS_URL                One server, e.g. "redis://redis:6379". Used when
 *                            REDIS_SENTINELS is empty.
 *   REDIS_SENTINELS          Comma-separated sentinels, e.g.
 *                            "tcp://sentinel-0:26379,tcp://sentinel-1:26379".
 *   REDIS_SENTINEL_SERVICE   The master's name in Sentinel.
 *   REDIS_PASSWORD           The Redis password, if any.
 *   REDIS_SENTINEL_PASSWORD  The sentinels' own password, if any.
 *   REDIS_DB                 The database number.
 */
final class RedisClientFactory
{
    /** How long to retry a command while Sentinel fails over. */
    private const int SENTINEL_RETRY_LIMIT = 10;
    private const int SENTINEL_RETRY_WAIT_MS = 1000;

    public static function create(
        string $url,
        string $sentinels,
        string $sentinelService,
        string $password,
        string $sentinelPassword,
        string $database,
    ): ClientInterface {
        $parameters = array_filter([
            'password' => $password,
            'database' => $database === '' ? null : (int) $database,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $sentinelList = array_values(array_filter(array_map('trim', explode(',', $sentinels))));
        if ($sentinelList === []) {
            return new Client($url, ['parameters' => $parameters]);
        }
        if ($sentinelService === '') {
            throw new \InvalidArgumentException('REDIS_SENTINEL_SERVICE is required with REDIS_SENTINELS.');
        }

        $sentinelParameters = [];
        foreach ($sentinelList as $sentinel) {
            $sentinelParameters[] = Parameters::parse($sentinel)
                + ($sentinelPassword === '' ? [] : ['password' => $sentinelPassword]);
        }

        return new Client($sentinelParameters, [
            'parameters' => $parameters,
            // Every command goes to the master, reads too: a replica may lag
            // behind, and the queue must see its own writes.
            'replication' => static function (
                array $sentinels,
                OptionsInterface $options,
            ) use ($sentinelService): SentinelReplication {
                $replication = new SentinelReplication(
                    $sentinelService,
                    $sentinels,
                    $options->connections,
                    new class extends ReplicationStrategy {
                        public function isReadOperation(CommandInterface $command): bool
                        {
                            return false;
                        }
                    },
                );
                // On a connection error, ask the sentinels for the master
                // again and retry, so a failover doesn't fail the command.
                $replication->setRetryLimit(self::SENTINEL_RETRY_LIMIT);
                $replication->setRetryWait(self::SENTINEL_RETRY_WAIT_MS);
                $replication->setUpdateSentinels(true);

                return $replication;
            },
        ]);
    }
}
