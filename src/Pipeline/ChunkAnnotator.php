<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * Annotates chunks with snippet and fragment.
 */
class ChunkAnnotator
{
    /**
     * Populate metadata on each chunk.
     *
     * @phpstan-param Chunk[] $chunks
     * @phpstan-param HeadingFragment[] $headingFragments
     *
     * @return Chunk[]
     *   The chunks with snippet and fragment populated.
     */
    public function annotate(array $chunks, array $headingFragments): array
    {
        $perSectionFragment = $this->buildSectionFragmentMap($chunks, $headingFragments);

        foreach ($chunks as $i => $chunk) {
            $chunk->snippet = SnippetRenderer::render($chunk->text);
            $chunk->fragment = $perSectionFragment[$i] ?? null;
        }
        return $chunks;
    }

    /**
     * Map chunk indexes to fragments for chunks.
     *
     * The extractor walks the DOM and the chunker walks cleaned Markdown.
     * They can disagree on which headings exist (HtmlCleaner drops wrappers,
     * Markdown may add Markdown syntax), so Heading::matches() compares
     * normalized text. Each fragment is consumed once found so duplicate headings
     * resolve in document order.
     *
     * @phpstan-param \App\Pipeline\Chunk[] $chunks
     * @phpstan-param HeadingFragment[] $headingFragments
     * @phpstan-return array<string>
     */
    private function buildSectionFragmentMap(array $chunks, array $headingFragments): array
    {
        $perChunkIndex = [];
        $sectionFragment = null;
        $previousSection = null;

        foreach ($chunks as $i => $chunk) {
            $heading = $chunk->heading;
            $section = $heading === null ? null : [$heading->title, $heading->level, $chunk->parent];

            // A long heading section produces multiple sub-chunks that share the same
            // (title, level, parent). Sub-chunks should all get the same fragment, so
            // we only consume an entry when that triple changes.
            if ($section !== $previousSection) {
                $sectionFragment = null;
                if ($heading !== null && in_array($heading->level, [2, 3], true)) {
                    $matchIndex = array_find_key(
                        $headingFragments,
                        static fn (HeadingFragment $entry) => $entry->heading->matches($heading),
                    );

                    if ($matchIndex !== null) {
                        $entry = $headingFragments[$matchIndex];
                        unset($headingFragments[$matchIndex]);
                        if ($entry->fragment !== null && $entry->fragment !== '') {
                            $sectionFragment = $entry->fragment;
                        }
                    }
                }
                $previousSection = $section;
            }

            if ($sectionFragment !== null) {
                $perChunkIndex[$i] = $sectionFragment;
            }
        }

        return $perChunkIndex;
    }
}
