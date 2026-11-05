<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CrawlerConfig;
use App\Queue\QueueFolder;
use App\Queue\QueueWriter;
use App\Queue\Spool;
use App\Tests\Unit\CollectingLogger;
use App\Webhook\Webhook;
use App\Worker\Crawler;
use App\Worker\Reindexer;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Indexes a fake site with the real crawler and Elasticsearch, then updates
 * and deletes pages through the webhook and the worker.
 *
 * Runs in the worker container, which has the crawler:
 *
 *   docker compose run --rm worker vendor/bin/phpunit --testsuite integration
 *
 * Uses the crawl settings from config/crawler.yml (excluded tags, extraction
 * rules, the hel-fi pipeline, the Elasticsearch connection), with only the
 * site and the index replaced: a throwaway index, deleted afterwards.
 */
#[CoversNothing]
final class IndexingTest extends TestCase
{
    private const string TOKEN = 'integration-test';

    private static TestSite $site;
    private static Client $elasticsearch;
    private static string $index;
    private static string $temporary;
    private static string $configFile;
    /** @var array<string, mixed> */
    private static array $config;

    /** What the crawler printed, for failure messages. */
    private static string $crawlerOutput = '';

    public static function setUpBeforeClass(): void
    {
        self::$site = new TestSite();
        self::$temporary = sys_get_temp_dir() . '/helfi-search-integration-' . bin2hex(random_bytes(6));
        mkdir(self::$temporary, 0775, true);

        // The project's settings, for a fake site and a throwaway index.
        $project = CrawlerConfig::fromFile(CrawlerConfig::path());
        $config = $project->toArray();
        $domain = array_values($project->domains())[0];
        $domain['url'] = self::$site->url;
        $config['domains'] = [$domain];
        self::$index = 'hel-fi-test-' . bin2hex(random_bytes(4));
        $config['output_index'] = self::$index;
        // The crawler refuses to crawl local addresses by default.
        $config['loopback_allowed'] = true;
        self::$config = $config;
        self::$configFile = self::$temporary . '/crawler.yml';
        file_put_contents(self::$configFile, Yaml::dump($config, 10, 2));

        self::$elasticsearch = new Client([
            'base_uri' => $project->elasticsearchUrl(),
            RequestOptions::TIMEOUT => 30,
        ]);
        // The pipeline the crawler indexes through, as deployed.
        $elasticsearch = $config['elasticsearch'];
        self::assertIsArray($elasticsearch);
        $pipeline = $elasticsearch['pipeline'] ?? null;
        self::assertIsString($pipeline);
        self::$elasticsearch->put("/_ingest/pipeline/$pipeline", [
            RequestOptions::BODY => file_get_contents(dirname(__DIR__, 2) . '/config/pipeline.json'),
            RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
        ]);

        $site = self::$site;
        $site->write('/index.html', $site->page('Front page', 'Welcome to the test site.', links: [
            '/page-a.html' => 'Page A',
            '/page-b.html' => 'Page B',
            '/page-c.html' => 'Page C',
            '/page-c-copy.html' => 'Copy of page C',
        ]));
        $site->write('/page-a.html', $site->page(
            'Page A',
            'Original content of page A.',
            description: 'Description of page A',
            image: 'https://example.com/a.jpg',
            links: ['/index.html' => 'Front page'],
        ));
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

    public static function tearDownAfterClass(): void
    {
        self::$elasticsearch->delete('/' . self::$index, [RequestOptions::HTTP_ERRORS => false]);
        self::$site->stop();
        (new Filesystem())->remove(self::$temporary);
    }

    private function crawler(): Crawler
    {
        return new Crawler(output: static function (string $type, string $text): void {
            self::$crawlerOutput .= $text;
        });
    }

    /**
     * Sends a change through the webhook, then runs the worker once.
     *
     * @param array<string, string> $changes Actions by page path.
     */
    private function queueAndProcess(array $changes): void
    {
        $logger = new CollectingLogger();
        $folder = new QueueFolder(self::$temporary . '/queue');
        $webhook = new Webhook(new QueueWriter($folder), $logger, self::TOKEN, [self::$site->url]);
        foreach ($changes as $path => $action) {
            $body = json_encode(['url' => self::$site->pageUrl($path), 'action' => $action], JSON_THROW_ON_ERROR);
            $response = $webhook->handle(Request::create('/webhook', 'POST', server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            ], content: $body));
            $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
            usleep(10);
        }

        $reindexer = new Reindexer(new Spool($folder, $logger), self::$configFile, $logger, $this->crawler());
        $this->assertTrue(
            $reindexer->run(),
            implode("\n", $logger->messages('error')) . "\n" . self::$crawlerOutput,
        );
    }

    /**
     * The indexed document for a URL (by its URL or as an additional URL).
     *
     * @return array<string, mixed>|null The document's fields.
     */
    private function document(string $path): ?array
    {
        $url = self::$site->pageUrl($path);
        self::$elasticsearch->post('/' . self::$index . '/_refresh');
        $response = self::$elasticsearch->post('/' . self::$index . '/_search', [RequestOptions::JSON => [
            'query' => ['bool' => ['should' => [
                ['term' => ['url.keyword' => $url]],
                ['term' => ['additional_urls.keyword' => $url]],
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
     * The document's body text.
     *
     * @param array<string, mixed>|null $document
     */
    private function body(?array $document): string
    {
        $this->assertNotNull($document);
        $body = $document['body'] ?? null;
        $this->assertIsString($body);

        return $body;
    }

    public function testFullCrawlIndexesTheSite(): void
    {
        $this->crawler()->crawl(self::$config);

        $page = $this->document('/page-a.html');
        $this->assertNotNull($page, "Page A was not indexed. Crawler output:\n" . self::$crawlerOutput);

        $this->assertSame('Page A', $page['title']);
        $this->assertSame('Description of page A', $page['meta_description']);
        $this->assertSame('https://example.com/a.jpg', $page['og_image']);
        $this->assertStringContainsString('Original content of page A.', $this->body($page));
        foreach (['Site header text', 'Navigation text', 'Sidebar text', 'Site footer text'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $this->body($page), 'exclude_tags');
        }
        // Removed by the hel-fi pipeline.
        $removed = ['links', 'headings', '_reduce_whitespace', '_run_ml_inference', '_extract_binary_content'];
        foreach ($removed as $field) {
            $this->assertArrayNotHasKey($field, $page, 'config/pipeline.json');
        }

        $this->assertNotNull($this->document('/page-b.html'));
        $copy = $this->document('/page-c-copy.html');
        $this->assertNotNull($copy);
        $this->assertSame(self::$site->pageUrl('/page-c.html'), $copy['url'], 'Stored under the canonical URL.');
    }

    #[Depends('testFullCrawlIndexesTheSite')]
    public function testUpdateThroughTheQueue(): void
    {
        $before = $this->document('/page-b.html');
        // last_crawled_at has one-second precision.
        sleep(1);
        self::$site->write('/page-a.html', self::$site->page(
            'Page A, updated',
            'Updated content of page A.',
            description: 'New description',
        ));

        $this->queueAndProcess(['/page-a.html' => 'update']);

        $page = $this->document('/page-a.html');
        $this->assertNotNull($page);
        $this->assertSame('Page A, updated', $page['title'] ?? null);
        $this->assertSame('New description', $page['meta_description'] ?? null);
        $this->assertStringContainsString('Updated content of page A.', $this->body($page));
        $this->assertStringNotContainsString('Original content', $this->body($page));
        $this->assertEmpty($page['og_image'] ?? null, 'The image was removed from the page.');

        $this->assertSame($before, $this->document('/page-b.html'), 'Other pages are not recrawled or purged.');
    }

    #[Depends('testUpdateThroughTheQueue')]
    public function testDeleteThroughTheQueue(): void
    {
        $this->queueAndProcess(['/page-b.html' => 'delete']);

        $this->assertNull($this->document('/page-b.html'));
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
     * remove its document. Sites must send a delete.
     */
    #[Depends('testDeleteThroughTheQueue')]
    public function testUpdateOfARemovedPageKeepsTheDocument(): void
    {
        self::$site->remove('/page-a.html');

        $this->queueAndProcess(['/page-a.html' => 'update']);

        $this->assertNotNull($this->document('/page-a.html'));
    }

    #[Depends('testUpdateOfARemovedPageKeepsTheDocument')]
    public function testUpdateAndDeleteInOneBatch(): void
    {
        self::$site->write('/page-b.html', self::$site->page('Page B, back', 'Page B is back.'));

        $this->queueAndProcess(['/page-a.html' => 'delete', '/page-b.html' => 'update']);

        $this->assertNull($this->document('/page-a.html'));
        $this->assertSame('Page B, back', $this->document('/page-b.html')['title'] ?? null);
    }
}
