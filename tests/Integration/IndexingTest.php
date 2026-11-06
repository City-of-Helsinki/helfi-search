<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Embedding\EmbeddingModel;
use App\Embedding\FakeEmbeddingClient;
use App\Indexer\Indexer;
use App\Indexer\Indexes;
use App\Indexer\IndexSetup;
use App\Indexer\PageProcessor;
use App\Pipeline\ChunkAnnotator;
use App\Pipeline\ContentChunker;
use App\Pipeline\HtmlCleaner;
use App\Pipeline\TextPipeline;
use App\Queue\QueueFolder;
use App\Queue\Spool;
use App\Tests\KernelEnvironmentTrait;
use App\Tests\Unit\CollectingLogger;
use App\Worker\Crawler;
use App\Worker\Elasticsearch;
use App\Worker\Reindexer;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Indexes a fake site with the real crawler and Elasticsearch, then updates
 * and deletes pages through the webhook and the worker. Embeddings are made
 * up (FakeEmbeddingClient), and counted.
 *
 * Runs in the worker container, which has the crawler:
 *
 *   docker compose run --rm worker vendor/bin/phpunit --testsuite integration
 *
 * Uses the crawl settings from config/crawler.yml (and its Elasticsearch
 * connection), with only the site and the indexes replaced: throwaway
 * indexes, deleted afterwards.
 */
#[CoversNothing]
final class IndexingTest extends WebTestCase
{
    use KernelEnvironmentTrait;

    private const string TOKEN = 'integration-test';

    private static TestSite $site;
    private static Client $elasticsearch;
    private static string $prefix;
    private static Indexes $indexes;
    private static string $temporary;
    private static string $configFile;
    private static CrawlerConfig $config;
    private static FakeEmbeddingClient $embeddings;

    /** What the crawler printed, for failure messages. */
    private static string $crawlerOutput = '';

    private static function longText(string $topic): string
    {
        return str_repeat("Information about $topic. ", 40);
    }

    public static function setUpBeforeClass(): void
    {
        self::$site = new TestSite();
        self::$temporary = sys_get_temp_dir() . '/helfi-search-integration-' . bin2hex(random_bytes(6));
        mkdir(self::$temporary, 0775, true);
        self::$embeddings = new FakeEmbeddingClient();

        // The project's settings, for a fake site and throwaway indexes.
        $project = Yaml::parseFile(dirname(__DIR__, 2) . '/config/crawler.yml');
        self::assertIsArray($project);
        self::assertIsArray($project['domains'] ?? null);
        $domain = $project['domains'][0];
        self::assertIsArray($domain);
        $domain['url'] = self::$site->url;
        $project['domains'] = [$domain];
        self::$prefix = 'hel-fi-test-' . bin2hex(random_bytes(4));
        $project['output_index'] = self::$prefix . '-pages';
        // The crawler refuses to crawl local addresses by default.
        $project['loopback_allowed'] = true;
        self::$configFile = self::$temporary . '/crawler.yml';
        file_put_contents(self::$configFile, Yaml::dump($project, 10, 2));
        self::$config = CrawlerConfig::fromFile(self::$configFile);
        self::$indexes = new Indexes(self::$prefix, self::$prefix . '-cache');

        self::$elasticsearch = new Client([
            'base_uri' => self::$config->elasticsearchUrl(),
            RequestOptions::TIMEOUT => 30,
        ]);
        (new IndexSetup(self::$indexes, new CollectingLogger()))
            ->run(Elasticsearch::fromConfig(self::$config), self::$config);

        $site = self::$site;
        $site->write('/index.html', $site->page('Front page', 'Welcome to the test site.', links: [
            '/page-a.html' => 'Page A',
            '/page-b.html' => 'Page B',
            '/page-c.html' => 'Page C',
            '/page-c-copy.html' => 'Copy of page C',
        ], bundle: 'landing_page'));
        $site->write('/page-a.html', self::pageA('Original'));
        $site->write('/page-b.html', $site->page(
            'Page B',
            'Content of page B.',
            links: ['/index.html' => 'Front page'],
        ));
        $site->write('/page-c.html', $site->page('Page C', 'Content of page C.'));
        // The same page under another URL: indexed as page C.
        $site->write('/page-c-copy.html', $site->page(
            'Page C',
            'Content of page C.',
            canonical: $site->pageUrl('/page-c.html'),
        ));
    }

    /**
     * Page A: long enough for one chunk per section.
     */
    private static function pageA(string $version, string $image = 'https://example.com/a.jpg'): string
    {
        return self::$site->page(
            'Page A',
            'Content of page A.',
            image: $image,
            links: ['/index.html' => 'Front page'],
            bundle: 'news_article',
            sections: [
                'apply' => ['How to apply', self::longText("applying ($version)")],
                'contact' => ['Contact', self::longText('contacting us')],
            ],
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$prefix . '-v1', self::$prefix . '-pages', self::$prefix . '-cache'] as $index) {
            self::$elasticsearch->delete("/$index", [RequestOptions::HTTP_ERRORS => false]);
        }
        self::$site->stop();
        (new Filesystem())->remove(self::$temporary);
    }

    private function indexer(): Indexer
    {
        $crawler = new Crawler(output: static function (string $type, string $text): void {
            self::$crawlerOutput .= $text;
        });
        $pipeline = new TextPipeline(new HtmlCleaner(), new ContentChunker(), new ChunkAnnotator());

        return new Indexer(
            $crawler,
            new PageProcessor($pipeline),
            self::$embeddings,
            self::$indexes,
            new CollectingLogger(),
        );
    }

    private function fullCrawl(): void
    {
        $this->indexer()->crawl(self::$config, self::$config->toArray(), sweep: true);
    }

    /**
     * Sends changes through the webhook, then runs the worker once.
     *
     * @param array<string, string> $changes Actions by page path.
     */
    private function queueAndProcess(array $changes): void
    {
        $queueDir = self::$temporary . '/queue';
        self::configureKernel($queueDir, self::$configFile, self::TOKEN);
        $client = self::createClient();
        foreach ($changes as $path => $action) {
            $body = json_encode(['url' => self::$site->pageUrl($path), 'action' => $action], JSON_THROW_ON_ERROR);
            $client->request('POST', '/webhook', server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            ], content: $body);
            $response = $client->getResponse();
            $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
            usleep(10);
        }

        $logger = new CollectingLogger();
        $reindexer = new Reindexer(
            new Spool(new QueueFolder($queueDir), $logger),
            new CrawlerConfigLoader(self::$configFile),
            $logger,
            $this->indexer(),
        );
        $this->assertTrue(
            $reindexer->run(),
            implode("\n", $logger->messages('error')) . "\n" . self::$crawlerOutput,
        );
    }

    /**
     * The document of a URL (by its URL or as an additional URL), by default
     * in the search index.
     *
     * @return array<string, mixed>|null The document's fields.
     */
    private function document(string $path, ?string $index = null): ?array
    {
        $index ??= self::$indexes->search;
        $url = self::$site->pageUrl($path);
        self::$elasticsearch->post("/$index/_refresh");
        $response = self::$elasticsearch->post("/$index/_search", [RequestOptions::JSON => [
            'query' => ['bool' => ['should' => [
                ['term' => ['url' => $url]],
                ['term' => ['additional_urls' => $url]],
            ]]],
        ]]);
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        $this->assertIsArray($result['hits'] ?? null);
        $hits = $result['hits']['hits'] ?? null;
        $this->assertIsList($hits);
        $this->assertLessThanOrEqual(1, count($hits), "One document for $url");
        if ($hits === []) {
            return null;
        }

        $this->assertIsArray($hits[0]);
        $source = $hits[0]['_source'] ?? null;
        $this->assertIsArray($source);
        /** @var array<string, mixed> $source Document fields. */
        return $source;
    }

    /**
     * @param array<string, mixed>|null $document
     * @return list<array<string, mixed>> The document's chunks.
     */
    private function chunks(?array $document): array
    {
        $this->assertNotNull($document);
        $chunks = $document[EmbeddingModel::DEFAULT->field()] ?? null;
        $this->assertIsList($chunks);
        /** @var list<array<string, mixed>> $chunks */
        return $chunks;
    }

    /**
     * @param array<string, mixed>|null $document
     */
    private function markdown(?array $document): string
    {
        $this->assertNotNull($document);
        $markdown = $document['markdown'] ?? null;
        $this->assertIsString($markdown);

        return $markdown;
    }

    private function documentCount(string $index): int
    {
        self::$elasticsearch->post("/$index/_refresh");
        $result = json_decode((string) self::$elasticsearch->get("/$index/_count")->getBody(), true);
        $this->assertIsArray($result);
        $this->assertIsInt($result['count'] ?? null);

        return $result['count'];
    }

    public function testFullCrawlIndexesTheSite(): void
    {
        $this->fullCrawl();

        $page = $this->document('/page-a.html');
        $this->assertNotNull($page, "Page A was not indexed. Crawler output:\n" . self::$crawlerOutput);
        $this->assertSame('Page A', $page['label']);
        $this->assertSame('news_article', $page['entity_bundle'], 'From <meta name="helfi_content_type">.');
        $this->assertSame('fi', $page['search_api_language']);
        $this->assertSame('https://example.com/a.jpg', $page['og_image']);
        $this->assertSame((string) parse_url(self::$site->url, PHP_URL_HOST), $page['instance']);

        $markdown = $this->markdown($page);
        $this->assertStringContainsString('Content of page A.', $markdown);
        $this->assertStringContainsString('## How to apply', $markdown);
        foreach (['Site header text', 'Navigation text', 'Sidebar text', 'Site footer text'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $markdown);
        }

        $chunks = $this->chunks($page);
        $this->assertCount(3, $chunks, 'The intro and one chunk per section.');
        $this->assertSame([null, 'apply', 'contact'], array_column($chunks, 'fragment'), 'Section anchors.');
        $content = $chunks[2]['content'] ?? null;
        $this->assertIsString($content);
        $this->assertStringStartsWith('Information about contacting us.', $content);
        $this->assertArrayNotHasKey('vector', $chunks[0], 'Vectors are only in the kNN index.');
        $this->assertSame(self::$embeddings->embedded, $this->documentCount(self::$indexes->embeddingCache));

        $this->assertSame('landing_page', $this->document('/index.html')['entity_bundle'] ?? null);
        $this->assertNotNull($this->document('/page-b.html'));
        $copy = $this->document('/page-c-copy.html');
        $this->assertNotNull($copy);
        $this->assertSame(self::$site->pageUrl('/page-c.html'), $copy['url'], 'Stored under the canonical URL.');
    }

    #[Depends('testFullCrawlIndexesTheSite')]
    public function testUnchangedPagesAreNotEmbeddedAgain(): void
    {
        $before = self::$embeddings->embedded;
        $page = $this->document('/page-a.html');

        $this->fullCrawl();

        $this->assertSame($before, self::$embeddings->embedded);
        $this->assertSame($page['content_hash'] ?? null, $this->document('/page-a.html')['content_hash'] ?? null);
    }

    #[Depends('testUnchangedPagesAreNotEmbeddedAgain')]
    public function testUpdateThroughTheQueueEmbedsOnlyTheChangedSection(): void
    {
        $before = $this->document('/page-b.html');
        $embedded = self::$embeddings->embedded;
        // last_crawled_at has one-second precision.
        sleep(1);
        // Changes the "How to apply" section. (A change to the intro would
        // change every chunk: each includes its parent section's snippet.)
        self::$site->write('/page-a.html', self::pageA('Updated', image: ''));

        $this->queueAndProcess(['/page-a.html' => 'update']);

        $page = $this->document('/page-a.html');
        $markdown = $this->markdown($page);
        $this->assertStringContainsString('applying (Updated)', $markdown);
        $this->assertStringNotContainsString('applying (Original)', $markdown);
        $this->assertNull($page['og_image'] ?? null, 'The image was removed from the page.');
        $this->assertCount(3, $this->chunks($page));
        $this->assertSame(1, self::$embeddings->embedded - $embedded, 'Only the changed chunk is embedded.');

        $this->assertSame($before, $this->document('/page-b.html'), 'Other pages are not recrawled.');
    }

    #[Depends('testUpdateThroughTheQueueEmbedsOnlyTheChangedSection')]
    public function testFullCrawlAndWebhookProduceTheSameDocument(): void
    {
        $fromWebhook = $this->document('/page-a.html');
        $embedded = self::$embeddings->embedded;

        $this->fullCrawl();

        $fromCrawl = $this->document('/page-a.html');
        $this->assertNotNull($fromWebhook);
        $this->assertNotNull($fromCrawl);
        $times = ['processed_at' => null, 'last_crawled_at' => null];
        $this->assertSame(array_diff_key($fromWebhook, $times), array_diff_key($fromCrawl, $times));
        $this->assertSame($embedded, self::$embeddings->embedded);
    }

    #[Depends('testFullCrawlAndWebhookProduceTheSameDocument')]
    public function testDeleteThroughTheQueue(): void
    {
        $this->queueAndProcess(['/page-b.html' => 'delete']);

        $this->assertNull($this->document('/page-b.html'));
        $this->assertNull($this->document('/page-b.html', self::$prefix . '-pages'), "Also from the crawler's index.");
        $this->assertNotNull($this->document('/page-a.html'), 'Other pages stay.');
    }

    #[Depends('testDeleteThroughTheQueue')]
    public function testDeleteByANonCanonicalUrl(): void
    {
        $this->queueAndProcess(['/page-c-copy.html' => 'delete']);

        $this->assertNull($this->document('/page-c.html'), 'Found through additional_urls.');
    }

    /**
     * A known limitation: an update for a page that no longer exists doesn't
     * remove its document. Sites must send a delete; otherwise the next full
     * crawl removes it.
     */
    #[Depends('testDeleteByANonCanonicalUrl')]
    public function testUpdateOfARemovedPageKeepsTheDocument(): void
    {
        self::$site->remove('/page-a.html');

        $this->queueAndProcess(['/page-a.html' => 'update']);

        $this->assertNotNull($this->document('/page-a.html'));
    }

    #[Depends('testUpdateOfARemovedPageKeepsTheDocument')]
    public function testFullCrawlRemovesPagesThatAreGone(): void
    {
        $this->assertGreaterThan(1, $this->documentCount(self::$indexes->embeddingCache));

        $this->fullCrawl();

        $this->assertNull($this->document('/page-a.html'));
        $this->assertNull($this->document('/page-a.html', self::$prefix . '-pages'), 'Purged by the crawler.');
        $this->assertNotNull($this->document('/index.html'));
        // Pages B and C were only deleted through the webhook: they're still
        // on the site, so the full crawl indexes them again.
        $this->assertNotNull($this->document('/page-b.html'));
        // One chunk each for the front page, B and C: page A's are pruned.
        $this->assertSame(3, $this->documentCount(self::$indexes->embeddingCache));
    }

    #[Depends('testFullCrawlRemovesPagesThatAreGone')]
    public function testUpdateAndDeleteInOneBatch(): void
    {
        self::$site->write('/page-b.html', self::$site->page('Page B, back', 'Page B is back.'));

        $this->queueAndProcess(['/index.html' => 'delete', '/page-b.html' => 'update']);

        $this->assertNull($this->document('/index.html'));
        $this->assertSame('Page B, back', $this->document('/page-b.html')['label'] ?? null);
    }
}
