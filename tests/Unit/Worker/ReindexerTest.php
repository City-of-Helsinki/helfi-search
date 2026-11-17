<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Queue\Action;
use App\Queue\QueueFolder;
use App\Queue\QueueWriter;
use App\Queue\Request;
use App\Queue\Spool;
use App\Site;
use App\Tests\Unit\CollectingLogger;
use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Worker\Reindexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reindexer::class)]
#[UsesClass(CrawlerConfig::class)]
#[UsesClass(CrawlerConfigLoader::class)]
#[UsesClass(QueueFolder::class)]
#[UsesClass(QueueWriter::class)]
#[UsesClass(Request::class)]
#[UsesClass(Spool::class)]
#[UsesClass(Site::class)]
final class ReindexerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string CONFIG = <<<'YAML'
        domains:
          - url: https://nuorten.hel.fi
            crawl_rules:
              - policy: deny
                type: begins
                pattern: /admin
            sitemap_urls: [https://nuorten.hel.fi/sitemap.xml]
          - url: https://avustukset.hel.fi
        output_index: hel-fi-pages
        max_crawl_depth: 5
        purge_crawl_enabled: true
        schedule:
          pattern: "0 12 * * *"
        YAML;

    private QueueFolder $folder;
    private string $configFile;
    private CollectingLogger $logger;
    private FakeIndexer $indexer;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
        $this->indexer = new FakeIndexer();
        $this->folder = new QueueFolder($this->temporaryDirectory() . '/queue');
        $this->configFile = $this->temporaryDirectory() . '/crawler.yml';
        file_put_contents($this->configFile, self::CONFIG);
    }

    private function queue(string $url, Action $action = Action::Update): void
    {
        (new QueueWriter($this->folder))->add(Request::now($url, $action));
        usleep(10);
    }

    private function runReindexer(): bool
    {
        $spool = new Spool($this->folder, $this->logger);

        return (new Reindexer($spool, new CrawlerConfigLoader($this->configFile), $this->logger, $this->indexer))
            ->run();
    }

    /**
     * The batch's error message, if it failed.
     */
    private function batchError(): string
    {
        $errors = $this->logger->messages('error');
        $this->assertCount(1, $errors);

        return $errors[0];
    }

    private function pending(): int
    {
        return (new QueueWriter($this->folder))->pendingCount();
    }

    /**
     * The config of the only crawl.
     *
     * @return array<string, mixed>
     */
    private function crawlConfig(): array
    {
        $this->assertCount(1, $this->indexer->crawls, 'One crawl.');
        [$config, $sweep] = $this->indexer->crawls[0];
        $this->assertFalse($sweep, 'A sweep would delete the rest of the index.');

        return $config;
    }

    /**
     * The seed URLs of the only crawl's first domain.
     *
     * @return mixed
     */
    private function seeds(): mixed
    {
        $domains = $this->crawlConfig()['domains'] ?? null;
        $this->assertIsArray($domains);
        $this->assertIsArray($domains[0] ?? null);

        return $domains[0]['seed_urls'] ?? null;
    }

    public function testEmptyQueue(): void
    {
        $this->assertTrue($this->runReindexer());
        $this->assertSame([], $this->indexer->crawls, 'Nothing is crawled.');
        $this->assertSame(['Queue is empty.'], $this->logger->messages('info'));
    }

    public function testCrawlsExactlyTheQueuedUrls(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->queue('https://nuorten.hel.fi/b/');

        $this->assertTrue($this->runReindexer());
        $this->assertSame(0, $this->pending(), 'The batch is done.');
        $info = $this->logger->messages('info');
        $this->assertMatchesRegularExpression('/^Processing batch \S+: 2 update\(s\), 0 delete\(s\)\.$/', $info[0]);
        $this->assertMatchesRegularExpression('/^Batch \S+ done\.$/', $info[1]);
        $this->assertSame([], $this->logger->messages('error'));

        $config = $this->crawlConfig();
        $this->assertSame(
            [[
                'url' => 'https://nuorten.hel.fi',
                'crawl_rules' => [['policy' => 'deny', 'type' => 'begins', 'pattern' => '/admin']],
                'seed_urls' => ['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/b/'],
            ]],
            $config['domains'] ?? null,
            "Only the batch's sites, with their own settings, without sitemaps.",
        );
        $this->assertSame(1, $config['max_crawl_depth'] ?? null, 'No links are followed.');
        $this->assertTrue($config['sitemap_discovery_disabled'] ?? null);
        $this->assertFalse($config['purge_crawl_enabled'] ?? null, 'A purge would delete the rest of the index.');
        $this->assertArrayNotHasKey('schedule', $config);
        $this->assertSame('hel-fi-pages', $config['output_index'] ?? null);
    }

    public function testSkipsSitesRemovedFromTheConfig(): void
    {
        $this->queue('https://www.hel.fi/fi');
        $this->queue('https://avustukset.hel.fi/fi/x');

        $this->assertTrue($this->runReindexer());
        $this->assertSame(
            [['url' => 'https://avustukset.hel.fi', 'seed_urls' => ['https://avustukset.hel.fi/fi/x']]],
            $this->crawlConfig()['domains'] ?? null,
        );
        $this->assertSame(
            ['Skipping https://www.hel.fi/fi: its site is no longer in crawler.yml.'],
            $this->logger->messages('warning'),
        );
    }

    public function testBatchWithOnlyUnknownSitesFails(): void
    {
        $this->queue('https://www.hel.fi/fi');

        $this->assertFalse($this->runReindexer());
        $this->assertSame(1, $this->pending(), 'The batch is returned to the queue.');
        $this->assertStringEndsWith('None of the URLs belong to a configured site.', $this->batchError());
    }

    public function testDeletesAndUpdates(): void
    {
        $this->queue('https://nuorten.hel.fi/a/', Action::Delete);
        $this->queue('https://nuorten.hel.fi/b/');
        $this->queue('https://nuorten.hel.fi/c/', Action::Delete);

        $this->assertTrue($this->runReindexer());
        $this->assertSame([['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/c/']], $this->indexer->deletes);
        $this->assertSame(['https://nuorten.hel.fi/b/'], $this->seeds());
        $this->assertContains('Deleted 2 document(s).', $this->logger->messages('info'));
    }

    public function testLatestRequestForAUrlWins(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->queue('https://nuorten.hel.fi/a/', Action::Delete);

        $this->assertTrue($this->runReindexer());
        $this->assertSame([['https://nuorten.hel.fi/a/']], $this->indexer->deletes);
        $this->assertSame([], $this->indexer->crawls);
    }

    public function testFailedCrawlReturnsTheBatch(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->indexer->failure = new \RuntimeException('1 page(s) failed to process.');

        $this->assertFalse($this->runReindexer());
        $this->assertSame(1, $this->pending());
        $this->assertStringContainsString('1 page(s) failed to process', $this->batchError());
    }

    public function testFailedDeleteReturnsTheBatch(): void
    {
        $this->queue('https://nuorten.hel.fi/a/', Action::Delete);
        $this->queue('https://nuorten.hel.fi/b/');
        $this->indexer->failure = new \RuntimeException('Elasticsearch is down.');

        $this->assertFalse($this->runReindexer());
        $this->assertSame(2, $this->pending());
        $this->assertSame([], $this->indexer->crawls, 'Deletes run first; the failure stops the batch.');
        $this->assertMatchesRegularExpression(
            '/^Batch \S+ failed and was returned to the queue: Elasticsearch is down\.$/',
            $this->batchError(),
        );
    }

    public function testUnreadableConfigReturnsTheBatch(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        file_put_contents($this->configFile, "output_index: hel-fi\n");

        $this->assertFalse($this->runReindexer());
        $this->assertSame(1, $this->pending());
        $this->assertStringContainsString('No domains configured', $this->batchError());
    }
}
