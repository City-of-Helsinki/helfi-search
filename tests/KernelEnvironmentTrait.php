<?php

declare(strict_types=1);

namespace App\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Points the test kernel at a temporary crawler.yml and the test's own Redis
 * keys, for KernelTestCase and WebTestCase tests. See when@test in
 * config/services.yaml.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait KernelEnvironmentTrait
{
    /**
     * Sets the environment for the kernels booted after this.
     *
     * @param string $crawlerConfig The crawler.yml to read.
     * @param string $redisPrefix The Redis key prefix: see RedisTrait.
     * @param string $token The webhook token; empty means none.
     * @param string|null $redisUrl Another Redis, e.g. one that's down.
     */
    protected static function configureKernel(
        string $crawlerConfig,
        string $redisPrefix,
        string $token = '',
        ?string $redisUrl = null,
    ): void {
        self::ensureKernelShutdown();

        $variables = [
            'TEST_CRAWLER_CONFIG' => $crawlerConfig,
            'REDIS_PREFIX' => $redisPrefix,
            // Takes precedence over WEBHOOK_TOKEN in the container's real
            // environment.
            'WEBHOOK_TOKEN' => $token,
        ];
        if ($redisUrl !== null) {
            $variables['REDIS_URL'] = $redisUrl;
        } else {
            unset($_ENV['REDIS_URL'], $_SERVER['REDIS_URL']);
        }
        foreach ($variables as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    /**
     * The messages logged to the "app" channel (the application's own
     * logger), with their placeholders replaced.
     *
     * @return list<string>
     */
    protected static function loggedMessages(string $level): array
    {
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);

        $messages = [];
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                foreach ($handler->getRecords() as $record) {
                    if (strtolower($record->level->getName()) === $level) {
                        $messages[] = $record->message;
                    }
                }
            }
        }

        return $messages;
    }
}
