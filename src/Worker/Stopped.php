<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Thrown to stop a batch midway, because the worker is shutting down.
 */
final class Stopped extends \Exception
{
}
