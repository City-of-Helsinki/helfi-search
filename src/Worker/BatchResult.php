<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * What Reindexer::run() did.
 */
enum BatchResult
{
    /** Nothing to claim. */
    case Idle;

    /** A batch was applied. */
    case Done;

    /** A batch failed and was returned to the queue, to be retried later. */
    case Failed;

    /** The worker is stopping: a batch was returned to the queue unfinished. */
    case Stopped;
}
