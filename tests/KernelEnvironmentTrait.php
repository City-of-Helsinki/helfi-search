<?php

declare(strict_types=1);

namespace App\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Points the test kernel at temporary files, for KernelTestCase and
 * WebTestCase tests. See when@test in config/services.yaml.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait KernelEnvironmentTrait
{
    /**
     * Sets the environment for the kernels booted after this.
     *
     * @param string $queueDir The queue folder.
     * @param string $crawlerConfig The crawler.yml to read.
     * @param string $token The webhook token; empty means none.
     */
    protected static function configureKernel(string $queueDir, string $crawlerConfig, string $token = ''): void
    {
        self::ensureKernelShutdown();

        $variables = [
            'TEST_QUEUE_DIR' => $queueDir,
            'TEST_CRAWLER_CONFIG' => $crawlerConfig,
            // Takes precedence over WEBHOOK_TOKEN in the container's real
            // environment.
            'WEBHOOK_TOKEN' => $token,
        ];
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
