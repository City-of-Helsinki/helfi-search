<?php

declare(strict_types=1);

namespace App\Embedding;

use Psr\Log\LoggerInterface;

/**
 * Creates the EmbeddingClient service (see config/services.yaml).
 */
final class EmbeddingClientFactory
{
    /**
     * Uses the real API when OPENAI_API_KEY is set. Without it, development
     * gets FakeEmbeddingClient; production gets the real client anyway, which
     * then fails every run until the key is set, rather than indexing made-up
     * vectors.
     */
    public static function create(
        string $apiKey,
        string $baseUrl,
        string $environment,
        LoggerInterface $logger,
    ): EmbeddingClient {
        if ($apiKey === '' && $environment !== 'prod') {
            $logger->notice('OPENAI_API_KEY is not set: using made-up embeddings.');
            return new FakeEmbeddingClient();
        }

        return new OpenAiEmbeddingClient($apiKey, $baseUrl, $logger);
    }
}
