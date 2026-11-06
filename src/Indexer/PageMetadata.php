<?php

declare(strict_types=1);

namespace App\Indexer;

/**
 * What the search shows and filters on, read from a page's HTML.
 */
final readonly class PageMetadata
{
    public function __construct(
        public ?string $title,
        public ?string $language,
        public ?string $bundle,
        public ?string $image,
    ) {
    }

    /**
     * Reads the metadata, before the pipeline cleans the document.
     *
     * - title: og:title (hel.fi sites set it to the content's own title),
     *   else <title>, else the first <h1>
     * - language: the language of <html lang>, e.g. "sv" for "sv-SE", as
     *   Drupal's language codes"
     * - bundle: <meta name="helfi_content_type">, e.g. "news_article"
     * - image: og:image
     */
    public static function fromDocument(\DOMDocument $document): self
    {
        $xpath = new \DOMXPath($document);

        $title = self::meta($xpath, 'property', 'og:title')
            ?? self::text($xpath, '//title')
            ?? self::text($xpath, '//h1');

        $lang = strtolower(trim((string) $document->documentElement?->getAttribute('lang')));
        $language = $lang === '' ? null : explode('-', $lang, 2)[0];

        return new self(
            $title,
            $language,
            self::meta($xpath, 'name', 'helfi_content_type'),
            self::meta($xpath, 'property', 'og:image'),
        );
    }

    private static function meta(\DOMXPath $xpath, string $attribute, string $name): ?string
    {
        $nodes = $xpath->query("//meta[@$attribute='$name']/@content");
        $value = $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';

        return $value === '' ? null : $value;
    }

    private static function text(\DOMXPath $xpath, string $expression): ?string
    {
        $nodes = $xpath->query($expression);
        $text = $nodes !== false && $nodes->length > 0 ? (string) $nodes->item(0)?->nodeValue : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }
}
