<?php

declare(strict_types=1);

namespace App;

use Psr\Log\AbstractLogger;

/**
 * Writes log lines to the container log (stderr):
 *
 *   [2026-11-05T06:00:00Z] [INFO] Batch 20261105T060000-1a2b3c4d done.
 *
 * {placeholders} in the message are replaced with the context values, as
 * PSR-3 describes.
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * @param resource|null $stream Where to write; stderr by default.
     */
    public function __construct(
        private $stream = null,
    ) {
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] [%s] %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper(is_string($level) ? $level : 'log'),
            self::interpolate((string) $message, $context),
        );

        if ($this->stream !== null) {
            fwrite($this->stream, $line);
        } else {
            file_put_contents('php://stderr', $line);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
