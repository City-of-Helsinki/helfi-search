<?php

declare(strict_types=1);

namespace App\Webhook;

use App\CrawlerConfigLoader;
use App\Queue\Action;
use App\Queue\QueueWriter;
use App\Queue\Request as QueueRequest;
use App\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The webhook's endpoints.
 *
 * POST /webhook  Queue a change. Body: {"url": "https://...", "action": "update"|"delete"}.
 *                "action" defaults to "update".
 * GET  /status   Number of changes waiting for the worker.
 * GET  /health   Health check for Docker and OpenShift probes: 200 if the
 *                queue folder is writable and the crawler config readable,
 *                503 if not.
 *
 * /webhook and /status require "Authorization: Bearer <token>" (see
 * TokenListener); /health doesn't, and reveals nothing else. Errors are JSON
 * too (see JsonErrorListener).
 *
 * The webhook only writes into the queue folder. It must not depend on
 * anything in App\Worker: the two run as separate, decoupled services. It
 * reads crawler.yml only for the sites to accept.
 */
#[AsController]
final class WebhookController
{
    public function __construct(
        private readonly QueueWriter $queue,
        private readonly CrawlerConfigLoader $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook', name: 'webhook', methods: ['POST'])]
    public function queueChange(Request $request): JsonResponse
    {
        $allowedOrigins = $this->allowedOrigins();
        if ($allowedOrigins === null) {
            return Json::error(Response::HTTP_SERVICE_UNAVAILABLE, 'The crawler configuration is not readable.');
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = null;
        }
        if (!is_string($data['url'] ?? null)) {
            return Json::error(
                Response::HTTP_BAD_REQUEST,
                'Expected a JSON body like {"url": "https://...", "action": "update"}.',
            );
        }

        $rawAction = $data['action'] ?? Action::Update->value;
        $action = is_string($rawAction) ? Action::tryFrom($rawAction) : null;
        if ($action === null) {
            return Json::error(Response::HTTP_BAD_REQUEST, 'action must be "update" or "delete".');
        }

        $url = $data['url'];
        // Schemes are case-insensitive, like hosts.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            return Json::error(Response::HTTP_BAD_REQUEST, 'url must be an absolute http(s) URL.');
        }
        // The fragment isn't part of the page.
        $url = explode('#', $url, 2)[0];

        if (!in_array(Site::origin($url), $allowedOrigins, true)) {
            return Json::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'That site is not accepted by this webhook.');
        }

        $this->queue->add(QueueRequest::now($url, $action));
        $this->logger->info('Queued {action}: {url}', ['action' => $action->value, 'url' => $url]);

        return Json::response(['queued' => ['url' => $url, 'action' => $action->value]], Response::HTTP_ACCEPTED);
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return Json::response(['pending' => $this->queue->pendingCount()]);
    }

    /**
     * Also answers HEAD, like every GET route.
     */
    #[Route('/health', name: TokenListener::PUBLIC_ROUTE, methods: ['GET'])]
    public function health(): JsonResponse
    {
        if (!$this->queue->isWritable()) {
            return Json::response(['status' => 'queue folder not writable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        // Without the sites nothing can be accepted.
        if ($this->allowedOrigins() === null) {
            return Json::response(
                ['status' => 'crawler configuration not readable'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return Json::response(['status' => 'ok']);
    }

    /**
     * The origins of the sites in crawler.yml, read on every request so edits
     * apply without a restart.
     *
     * @return list<string>|null NULL if the config can't be read.
     */
    private function allowedOrigins(): ?array
    {
        try {
            return array_map(Site::origin(...), $this->config->load()->sites());
        } catch (\Throwable $e) {
            $this->logger->error('Cannot read the crawler configuration: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            return null;
        }
    }
}
