<?php

declare(strict_types=1);

namespace App\Pipeline;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts cleaned HTML to Markdown while preserving semantic structure.
 */
final class MarkdownConverter
{
    /**
     * Convert HTML to Markdown.
     *
     * @param string $html
     *   Cleaned HTML to convert.
     *
     * @return string
     *   Markdown text.
     */
    public static function convert(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'italic_style' => '*',
            'bold_style' => '**',
            'hard_break' => false,
            'strip_placeholder_links' => true,
        ]);

        return $converter->convert($html);
    }
}
