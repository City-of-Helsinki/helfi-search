<?php

declare(strict_types=1);

namespace App;

/**
 * Writes timestamped lines to the container log.
 */
final class Log
{
    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        file_put_contents('php://stderr', sprintf("[%s] [%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $level, $message));
    }
}
