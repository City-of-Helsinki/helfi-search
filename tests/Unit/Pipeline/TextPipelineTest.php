<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

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
use Masterminds\HTML5;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextPipeline::class)]
#[CoversClass(Text::class)]
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
#[UsesClass(TextNormalizer::class)]
final class TextPipelineTest extends TestCase
{
    private function process(string $html): Document
    {
        $pipeline = new TextPipeline(new HtmlCleaner(['visually-hidden']), new ContentChunker(), new ChunkAnnotator());

        return $pipeline->process((new HTML5(['disable_html_ns' => true]))->loadHTML($html));
    }

    public function testShortPage(): void
    {
        $document = $this->process('<html><body><h1>Test Article</h1><p>Helsinki</p></body></html>');

        $this->assertSame("# Test Article\n\nHelsinki", $document->markdown);
        $this->assertCount(1, $document->chunks);
        $this->assertStringContainsString('Helsinki', (string) $document->chunks[0]);
        $this->assertSame('Helsinki', $document->chunks[0]->snippet);
    }

    public function testLongPageIsChunkedBySectionWithAnchors(): void
    {
        $section = str_repeat('Lorem ipsum dolor sit amet. ', 40);
        $document = $this->process(<<<HTML
            <html><body>
              <header>Site header</header>
              <main class="layout-main-wrapper">
                <h1>Services</h1>
                <span class="visually-hidden">Skip me</span>
                <h2 id="first">First section</h2><p>$section</p>
                <h2 id="second">Second section</h2><p>$section</p>
              </main>
              <footer>Site footer</footer>
            </body></html>
            HTML);

        $this->assertStringNotContainsString('Site header', $document->markdown);
        $this->assertStringNotContainsString('Skip me', $document->markdown);
        $this->assertCount(2, $document->chunks);
        $this->assertSame(['first', 'second'], array_map(static fn (Chunk $c) => $c->fragment, $document->chunks));
        $this->assertStringStartsWith("# Services\n## First section\n", (string) $document->chunks[0]);
    }

    public function testEmptyPage(): void
    {
        $this->assertSame([], $this->process('<html><body><nav>Menu</nav></body></html>')->chunks);
    }

    public function testTruncate(): void
    {
        $this->assertSame('short', Text::truncate('short', 10));
        $this->assertSame('Hello', Text::truncate('Hello world', 8), 'At a word boundary.');
        $this->assertSame('Helloworld', Text::truncate('Helloworldagain', 10), 'No boundary: a hard cut.');
        $this->assertSame('Hyvää', Text::truncate('Hyvää päivää', 9), 'Counts characters, not bytes.');
    }
}
