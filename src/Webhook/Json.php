<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The webhook's JSON responses.
 */
final class Json
{
    /**
     * @param array<string, mixed> $data
     */
    public static function response(array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        // Plain JSON; the default escapes slashes and quotes.
        return (new JsonResponse($data, $status))
            ->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function error(int $status, string $message): JsonResponse
    {
        return self::response(['error' => $message], $status);
    }
}
