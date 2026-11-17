<?php

/**
 * Webhook front controller, served by FrankenPHP (configured in the
 * helfi-search-webhook base image). The routes are in App\Webhook.
 */

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => Kernel::fromContext($context);
