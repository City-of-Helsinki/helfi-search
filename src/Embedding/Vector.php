<?php

declare(strict_types=1);

namespace App\Embedding;

/**
 * Stores vectors compactly: 32-bit floats, base64-encoded for a binary field.
 */
final class Vector
{
    /**
     * @param list<float> $vector
     */
    public static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /**
     * @return list<float>
     */
    public static function decode(string $encoded): array
    {
        $packed = base64_decode($encoded, true);
        $values = $packed === false ? false : unpack('g*', $packed);

        return $values === false
            ? []
            : array_values(array_map(static fn (mixed $v): float => is_float($v) ? $v : 0.0, $values));
    }
}
