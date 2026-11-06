<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Requires "Authorization: Bearer <WEBHOOK_TOKEN>" on every route but the
 * health check.
 *
 * Runs after the router (priority 32), so it knows the route; requests for
 * unknown routes get a 404 either way.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final readonly class TokenListener
{
    /** The route that needs no token: the health check. */
    public const string PUBLIC_ROUTE = 'health';

    /**
     * @param string $token The shared secret. Every request is refused if
     *   it's empty.
     */
    public function __construct(
        private string $token,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get('_route') === self::PUBLIC_ROUTE) {
            return;
        }

        if ($this->token === '') {
            $event->setResponse(Json::error(Response::HTTP_SERVICE_UNAVAILABLE, 'WEBHOOK_TOKEN is not set.'));
        } elseif (!hash_equals("Bearer $this->token", (string) $request->headers->get('Authorization'))) {
            $event->setResponse(Json::error(Response::HTTP_UNAUTHORIZED, 'Missing or invalid token.'));
        }
    }
}
