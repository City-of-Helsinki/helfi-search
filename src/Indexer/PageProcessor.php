<?php

declare(strict_types=1);

namespace App\Indexer;

use App\Embedding\EmbeddingModel;
use App\Pipeline\TextPipeline;
use Masterminds\HTML5;

/**
 * Turns a crawled page into a search document: metadata, Markdown, chunks.
 */
final readonly class PageProcessor
{
    public function __construct(
        private TextPipeline $pipeline,
    ) {
    }

    /**
     * @param string $instance The site, e.g. "nuorten.hel.fi".
     *
     * @throws \App\Pipeline\PipelineException If the pipeline fails.
     */
    public function process(Page $page, string $instance, EmbeddingModel $model): ProcessedPage
    {
        $document = (new HTML5(['disable_html_ns' => true, 'encoding' => 'UTF-8']))->loadHTML($page->html);
        $metadata = PageMetadata::fromDocument($document);
        $result = $this->pipeline->process($document);

        $fields = [
            'url' => $page->url,
            'additional_urls' => $page->additionalUrls,
            'instance' => $instance,
            'label' => $metadata->title,
            'search_api_language' => $metadata->language,
            'entity_bundle' => $metadata->bundle,
            'og_image' => $metadata->image,
            'markdown' => $result->markdown,
        ];

        $chunks = [];
        $hashed = [];
        foreach ($result->chunks as $chunk) {
            $key = EmbeddingCache::key($model, $chunk);
            // Identical chunks share a vector; keep the first.
            $chunks[$key] ??= $chunk;
            $hashed[] = [$key, $chunk->snippet, $chunk->fragment];
        }
        $fields['chunk_hashes'] = array_keys($chunks);

        $contentHash = hash('sha256', json_encode(
            [TextPipeline::VERSION, $fields, $hashed],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        return new ProcessedPage($page, $fields, $chunks, $contentHash);
    }
}
