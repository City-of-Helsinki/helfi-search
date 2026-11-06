<?php

declare(strict_types=1);

namespace App\Tests\Unit\Indexer;

use App\Embedding\EmbeddingModel;
use App\Indexer\EmbeddingCache;
use App\Indexer\Page;
use App\Indexer\PageMetadata;
use App\Indexer\PageProcessor;
use App\Indexer\ProcessedPage;
use App\Pipeline\Chunk;
use App\Pipeline\ChunkAnnotator;
use App\Pipeline\ContentChunker;
use App\Pipeline\Document;
use App\Pipeline\Heading;
use App\Pipeline\HeadingFragment;
use App\Pipeline\HeadingFragmentExtractor;
use App\Pipeline\HtmlCleaner;
use App\Pipeline\MarkdownConverter;
use App\Pipeline\SnippetRenderer;
use App\Pipeline\Text;
use App\Pipeline\TextNormalizer;
use App\Pipeline\TextPipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageProcessor::class)]
#[CoversClass(PageMetadata::class)]
#[CoversClass(Page::class)]
#[CoversClass(ProcessedPage::class)]
#[UsesClass(EmbeddingCache::class)]
#[UsesClass(Chunk::class)]
#[UsesClass(ChunkAnnotator::class)]
#[UsesClass(ContentChunker::class)]
#[UsesClass(Document::class)]
#[UsesClass(Heading::class)]
#[UsesClass(HeadingFragment::class)]
#[UsesClass(HeadingFragmentExtractor::class)]
#[UsesClass(HtmlCleaner::class)]
#[UsesClass(MarkdownConverter::class)]
#[UsesClass(SnippetRenderer::class)]
#[UsesClass(Text::class)]
#[UsesClass(TextNormalizer::class)]
#[UsesClass(TextPipeline::class)]
final class PageProcessorTest extends TestCase
{
    private const string PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="FI-fi">
        <head>
          <title>Opiskelu | Nuorten Helsinki</title>
          <meta property="og:title" content="Opiskelu">
          <meta property="og:image" content="https://nuorten.hel.fi/kuva.jpg">
          <meta name="helfi_content_type" content="landing_page" class="elastic">
        </head>
        <body><main class="layout-main-wrapper"><h1>Opiskelu</h1><p>Tietoa opiskelusta.</p></main></body>
        </html>
        HTML;

    private function process(string $html, string $url = 'https://nuorten.hel.fi/opiskelu'): ProcessedPage
    {
        $processor = new PageProcessor(new TextPipeline(new HtmlCleaner(), new ContentChunker(), new ChunkAnnotator()));
        $page = Page::fromSource('abc', [
            'url' => $url,
            'additional_urls' => ['https://nuorten.hel.fi/node/1'],
            'full_html' => $html,
            'last_crawled_at' => '2026-11-06T10:00:00Z',
        ]);

        return $processor->process($page, 'nuorten.hel.fi', EmbeddingModel::Large);
    }

    public function testFields(): void
    {
        $page = $this->process(self::PAGE);

        $this->assertSame([
            'url' => 'https://nuorten.hel.fi/opiskelu',
            'additional_urls' => ['https://nuorten.hel.fi/node/1'],
            'instance' => 'nuorten.hel.fi',
            'label' => 'Opiskelu',
            'search_api_language' => 'fi',
            'entity_bundle' => 'landing_page',
            'og_image' => 'https://nuorten.hel.fi/kuva.jpg',
            'markdown' => "# Opiskelu\n\nTietoa opiskelusta.",
            'chunk_hashes' => array_keys($page->chunks),
        ], $page->fields);
        $this->assertCount(1, $page->chunks);
        $this->assertSame('2026-11-06T10:00:00Z', $page->page->lastCrawledAt);
    }

    public function testTitleFallbacks(): void
    {
        $this->assertSame('Page | Site', $this->process('<title>Page | Site</title><h1>Heading</h1>')->fields['label']);
        $this->assertSame('Heading', $this->process('<h1> Heading </h1>')->fields['label']);
        $this->assertNull($this->process('<p>Text</p>')->fields['label']);
        $this->assertNull($this->process('<p>Text</p>')->fields['entity_bundle']);
    }

    public function testContentHash(): void
    {
        $hash = $this->process(self::PAGE)->contentHash;

        $this->assertSame($hash, $this->process(self::PAGE)->contentHash, 'Stable.');
        $this->assertNotSame($hash, $this->process(str_replace('opiskelusta', 'työstä', self::PAGE))->contentHash);
        $this->assertNotSame($hash, $this->process(str_replace('landing_page', 'page', self::PAGE))->contentHash);
        $this->assertNotSame($hash, $this->process(self::PAGE, 'https://nuorten.hel.fi/x')->contentHash);
    }

    public function testInvalidSource(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Page::fromSource('abc', ['url' => 'https://nuorten.hel.fi/']);
    }
}
