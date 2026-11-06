<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * String helpers for the pipeline.
 */
final class Text
{
    /**
     * Shortens text to at most $length characters, at a word boundary when
     * there is one (like Drupal's Unicode::truncate() with $wordsafe).
     */
    public static function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        // Keep everything up to the last whitespace or punctuation, if the
        // cut isn't already at one.
        if (preg_match('/^(.*\S)[\s\p{P}]/su', mb_substr($text, 0, $length + 1), $matches) === 1) {
            $cut = $matches[1];
        }

        return rtrim($cut);
    }
}
