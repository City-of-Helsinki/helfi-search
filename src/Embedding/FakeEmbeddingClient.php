<?php

declare(strict_types=1);

namespace App\Embedding;

/**
 * Made-up vectors, for development and tests: no API key, no cost.
 *
 * The same text always gets the same unit-length vector, so indexing behaves
 * as with the real API, but similarity between texts means nothing.
 */
final class FakeEmbeddingClient implements EmbeddingClient
{
    /** @var int The number of texts embedded so far. */
    public int $embedded = 0;

    public function embed(array $texts, EmbeddingModel $model): array
    {
        $vectors = [];
        foreach ($texts as $key => $text) {
            $vectors[(string) $key] = self::vector($text, $model->dimensions());
        }
        $this->embedded += count($texts);

        return $vectors;
    }

    /**
     * @return list<float>
     */
    private static function vector(string $text, int $dimensions): array
    {
        $values = [];
        for ($block = 0; count($values) < $dimensions; $block++) {
            // 16 values from each 32-byte hash.
            foreach (unpack('n*', hash('sha256', "$block\0$text", true)) ?: [] as $value) {
                $values[] = (is_int($value) ? $value : 0) / 32768 - 1;
            }
        }
        $values = array_slice($values, 0, $dimensions);

        $length = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $values))) ?: 1.0;

        return array_map(static fn (float $v): float => $v / $length, $values);
    }
}
