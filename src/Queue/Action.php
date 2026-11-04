<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * What to do with a queued URL.
 */
enum Action: string
{
    /** Recrawl the page and update its document. */
    case Update = 'update';

    /** Remove the page's document from the index. */
    case Delete = 'delete';
}
