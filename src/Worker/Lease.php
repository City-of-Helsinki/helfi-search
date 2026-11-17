<?php

declare(strict_types=1);

namespace App\Worker;

use App\Queue\Batch;
use App\Queue\RedisQueue;
use Psr\Log\LoggerInterface;

/**
 * Keeps a claimed batch's lease alive while it's processed. renew() is called
 * often (on every bit of crawler output), but only talks to Redis once in a
 * while.
 */
final class Lease
{
    private int $renewedAt;
    private bool $lost = false;

    public function __construct(
        private readonly RedisQueue $queue,
        private readonly Batch $batch,
        private readonly LoggerInterface $logger,
        private readonly int $seconds,
    ) {
        $this->renewedAt = time();
    }

    public function renew(): void
    {
        // Renew well before it expires, and only once in a while.
        if ($this->lost || time() - $this->renewedAt < max(1, intdiv($this->seconds, 10))) {
            return;
        }

        $this->renewedAt = time();
        try {
            if (!$this->queue->renew($this->batch, $this->seconds)) {
                $this->lost = true;
                $this->logger->warning(
                    'Batch {batch} took too long: its lease expired and it was returned to the queue.',
                    ['batch' => $this->batch->id],
                );
            }
        } catch (\Throwable $e) {
            // Redis may be failing over; try again next time.
            $this->logger->warning('Cannot renew the lease of batch {batch}: {error}', [
                'batch' => $this->batch->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
