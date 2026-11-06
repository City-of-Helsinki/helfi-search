<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns errors into JSON responses like {"error": "Not found."}, in every
 * environment.
 *
 * Runs after Symfony has logged the error (priority 0), but before its HTML
 * error page (-128).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final class JsonErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof MethodNotAllowedHttpException) {
            $allowed = $exception->getHeaders()['Allow'] ?? '';
            $message = 'Use ' . (is_string($allowed) ? $allowed : '') . '.';
        } elseif ($exception instanceof NotFoundHttpException) {
            $message = 'Not found.';
        } elseif ($exception instanceof HttpExceptionInterface) {
            $message = $exception->getMessage();
        } else {
            // Details stay in the log.
            $event->setResponse(Json::error(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal server error.'));
            return;
        }

        $response = Json::error($exception->getStatusCode(), $message);
        $response->headers->add($exception->getHeaders());
        $event->setResponse($response);
    }
}
