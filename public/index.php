<?php

/**
 * Webhook front controller, served by FrankenPHP (configured in the
 * helfi-search-webhook base image). The routes are in App\Webhook.
 *
 * Files in the queue folder must be group-writable for the worker. The image
 * sets umask 0002 for the whole server; PHP's umask() isn't safe to call here,
 * because FrankenPHP runs requests in threads of one process.
 */

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => Kernel::fromContext($context);
