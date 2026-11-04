<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Log;
use App\Queue\Action;
use App\Queue\QueueWriter;
use App\Queue\Request as QueueRequest;
use App\Site;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles the webhook's HTTP requests.
 *
 * POST /webhook  Queue a change. Body: {"url": "https://...", "action": "update"|"delete"}.
 *                "action" defaults to "update".
 * GET  /status   Number of changes waiting for the worker.
 * GET  /health   Health check for Docker and OpenShift probes: 200 if the
 *                queue folder is writable, 503 if not.
 *
 * /webhook and /status require "Authorization: Bearer <token>"; /health
 * doesn't, and reveals nothing else.
 *
 * The webhook only writes into the queue folder. It must not depend on
 * anything in App\Worker: the two run as separate, decoupled services.
 */
final class Webhook
{
    /** @var list<string> */
    private readonly array $allowedOrigins;

    /**
     * @param list<string> $allowedSites Site URLs to accept, e.g. "https://nuorten.hel.fi".
     */
    public function __construct(
        private readonly QueueWriter $queue,
        private readonly string $token,
        array $allowedSites,
    ) {
        $this->allowedOrigins = array_map(Site::origin(...), $allowedSites);
    }

    public function handle(Request $request): Response
    {
        if ($request->getPathInfo() === '/health') {
            return $this->health($request);
        }

        if ($this->token === '') {
            return $this->error(Response::HTTP_SERVICE_UNAVAILABLE, 'WEBHOOK_TOKEN is not set.');
        }
        if (!hash_equals("Bearer $this->token", (string) $request->headers->get('Authorization'))) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'Missing or invalid token.');
        }

        return match ($request->getPathInfo()) {
            '/webhook' => $request->isMethod('POST')
                ? $this->queueChange($request)
                : $this->error(Response::HTTP_METHOD_NOT_ALLOWED, 'Use POST.'),
            '/status' => $request->isMethod('GET')
                ? $this->json(['pending' => $this->queue->pendingCount()])
                : $this->error(Response::HTTP_METHOD_NOT_ALLOWED, 'Use GET.'),
            default => $this->error(Response::HTTP_NOT_FOUND, 'Not found.'),
        };
    }

    private function health(Request $request): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $this->error(Response::HTTP_METHOD_NOT_ALLOWED, 'Use GET.');
        }

        return $this->queue->isWritable()
            ? $this->json(['status' => 'ok'])
            : $this->json(['status' => 'queue folder not writable'], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function queueChange(Request $request): Response
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = null;
        }
        if (!is_string($data['url'] ?? null)) {
            return $this->error(Response::HTTP_BAD_REQUEST, 'Expected a JSON body like {"url": "https://...", "action": "update"}.');
        }

        $rawAction = $data['action'] ?? Action::Update->value;
        $action = is_string($rawAction) ? Action::tryFrom($rawAction) : null;
        if ($action === null) {
            return $this->error(Response::HTTP_BAD_REQUEST, 'action must be "update" or "delete".');
        }

        $url = $data['url'];
        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            return $this->error(Response::HTTP_BAD_REQUEST, 'url must be an absolute http(s) URL.');
        }
        // The fragment isn't part of the page.
        $url = explode('#', $url, 2)[0];

        if (!in_array(Site::origin($url), $this->allowedOrigins, true)) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'That site is not accepted by this webhook.');
        }

        $this->queue->add(QueueRequest::now($url, $action));
        Log::info("Queued {$action->value}: $url");

        return $this->json(['queued' => ['url' => $url, 'action' => $action->value]], Response::HTTP_ACCEPTED);
    }

    private function error(int $status, string $message): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        // Plain JSON; the default escapes slashes and quotes.
        return (new JsonResponse($data, $status))
            ->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
