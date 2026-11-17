<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * Turns a page's HTML into Markdown and chunks for embedding.
 *
 * Ported from the helfi_search Drupal module, which fetched the page
 * itself; here the crawler has fetched it. The stages:
 *  1) heading anchors, for linking to a chunk's section,
 *  2) cleaning (HtmlCleaner),
 *  3) Markdown conversion,
 *  4) normalization,
 *  5) chunking,
 *  6) snippets and anchors per chunk (ChunkAnnotator).
 */
final readonly class TextPipeline
{
    /**
     * The version of this pipeline's output. Part of every chunk's hash:
     * changing it re-embeds everything.
     */
    public const int VERSION = 1;

    public function __construct(
        private HtmlCleaner $htmlCleaner,
        private ContentChunker $contentChunker,
        private ChunkAnnotator $chunkAnnotator,
    ) {
    }

    /**
     * @param \DOMDocument $document The parsed page. Cleaning changes it.
     *
     * @throws PipelineException When a stage fails.
     */
    public function process(\DOMDocument $document): Document
    {
        $headingFragments = HeadingFragmentExtractor::extract($document);
        $cleanHtml = $this->htmlCleaner->clean($document);
        $markdown = MarkdownConverter::convert($cleanHtml);
        $normalized = TextNormalizer::normalize($markdown);
        $chunks = $this->contentChunker->chunk($normalized);

        return new Document(
            $normalized,
            $this->chunkAnnotator->annotate($chunks, $headingFragments),
        );
    }
}
