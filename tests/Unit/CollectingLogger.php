<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Psr\Log\AbstractLogger;

/**
 * Keeps log entries in memory, so tests can check them instead of printing
 * them.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : 'log',
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * The messages logged at a level, with {placeholders} filled in.
     *
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $replacements = [];
                foreach ($record['context'] as $key => $value) {
                    if (is_scalar($value) || $value instanceof \Stringable) {
                        $replacements['{' . $key . '}'] = (string) $value;
                    }
                }
                $messages[] = strtr($record['message'], $replacements);
            }
        }

        return $messages;
    }
}
