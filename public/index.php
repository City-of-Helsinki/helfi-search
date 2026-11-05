<?php

/**
 * Webhook front controller, served by FrankenPHP (configured in the
 * helfi-search-webhook base image).
 *
 * Reads the sites to accept from crawler.yml (CrawlerConfig::path()).
 *
 * Environment:
 *   WEBHOOK_TOKEN  Shared secret. All requests are refused if it's empty.
 */

declare(strict_types=1);

use App\CrawlerConfig;
use App\Queue\QueueFolder;
use App\Queue\QueueWriter;
use App\StderrLogger;
use App\Webhook\Webhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

// Files in the queue folder must be group-writable for the worker. The image
// sets umask 0002 for the whole server; PHP's umask() isn't safe to call here,
// because FrankenPHP runs requests in threads of one process.

$logger = new StderrLogger();

try {
    // Read on every request, so edits to crawler.yml apply without a restart.
    $sites = CrawlerConfig::fromFile(CrawlerConfig::path())->sites();
} catch (\Throwable $e) {
    // Without the site list nothing can be accepted, and /health must fail.
    $logger->error('Cannot read the crawler configuration: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
    (new JsonResponse(['error' => 'The crawler configuration is not readable.'], 503))->send();
    return;
}

$webhook = new Webhook(
    new QueueWriter(QueueFolder::default()),
    $logger,
    (string) getenv('WEBHOOK_TOKEN'),
    $sites,
);

try {
    $response = $webhook->handle(Request::createFromGlobals());
} catch (\Throwable $e) {
    $logger->error('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
    $response = new JsonResponse(['error' => 'Could not queue the change.'], 500);
}
$response->send();
