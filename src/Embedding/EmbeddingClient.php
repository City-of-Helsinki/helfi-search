<?php

declare(strict_types=1);

namespace App\Embedding;

/**
 * Turns texts into embedding vectors.
 *
 * The service is OpenAiEmbeddingClient, or FakeEmbeddingClient when no API
 * key is set outside production (see EmbeddingClientFactory).
 */
interface EmbeddingClient
{
    /** Longer inputs are cut to this many characters (see Chunk). */
    public const int MAX_INPUT_LENGTH = 8000;

    /**
     * @param array<string, string> $texts The texts, by any keys.
     * @return array<string, list<float>> The vectors, by the same keys.
     *
     * @throws EmbeddingException If the API fails or isn't configured.
     */
    public function embed(array $texts, EmbeddingModel $model): array;
}
