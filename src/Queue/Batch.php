<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Changes a worker claimed from the queue, to apply together.
 */
final readonly class Batch
{
    /**
     * @param array<string, Action> $actions The action per URL.
     */
    public function __construct(
        public string $id,
        public array $actions,
    ) {
    }

    /**
     * @return list<string>
     */
    public function urls(Action $action): array
    {
        return array_keys(array_filter($this->actions, static fn (Action $a): bool => $a === $action));
    }
}
