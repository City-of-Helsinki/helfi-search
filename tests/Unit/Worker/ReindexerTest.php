<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Queue\Action;
use App\Queue\Batch;
use App\Queue\RedisClientFactory;
use App\Queue\RedisQueue;
use App\Site;
use App\Tests\RedisTrait;
use App\Tests\Unit\CollectingLogger;
use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Worker\BatchResult;
use App\Worker\Lease;
use App\Worker\Reindexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reindexer::class)]
#[CoversClass(Lease::class)]
#[UsesClass(Batch::class)]
#[UsesClass(CrawlerConfig::class)]
#[UsesClass(CrawlerConfigLoader::class)]
#[UsesClass(RedisClientFactory::class)]
#[UsesClass(RedisQueue::class)]
#[UsesClass(Site::class)]
final class ReindexerTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use RedisTrait;

    private const string CONFIG = <<<'YAML'
        domains:
          - url: https://nuorten.hel.fi
            crawl_rules:
              - policy: deny
                type: begins
                pattern: /admin
            sitemap_urls: [https://nuorten.hel.fi/sitemap.xml]
          - url: https://avustukset.hel.fi
        max_crawl_depth: 5
        schedule:
          pattern: "0 12 * * *"
        YAML;

    private RedisQueue $queue;
    private string $configFile;
    private CollectingLogger $logger;
    private FakeIndexer $indexer;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
        $this->indexer = new FakeIndexer();
        // Failed changes can be retried right away.
        $this->queue = new RedisQueue($this->redis(), $this->redisPrefix(), retryDelay: 0);
        $this->configFile = $this->temporaryDirectory() . '/crawler.yml';
        file_put_contents($this->configFile, self::CONFIG);
    }

    private function queue(string $url, Action $action = Action::Update): void
    {
        $this->queue->add($url, $action);
    }

    private function runReindexer(?\Closure $shouldStop = null): BatchResult
    {
        return (new Reindexer($this->queue, new CrawlerConfigLoader($this->configFile), $this->logger, $this->indexer))
            ->run(100, 0, 600, $shouldStop);
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
        return $this->queue->stats()['pending'];
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
     * @return list<string>
     */
    private function seeds(): array
    {
        $domains = $this->crawlConfig()['domains'] ?? null;
        $this->assertIsArray($domains);
        $this->assertIsArray($domains[0] ?? null);
        $seeds = $domains[0]['seed_urls'] ?? null;
        $this->assertIsList($seeds);
        /** @var list<string> $seeds */
        sort($seeds);

        return $seeds;
    }

    public function testEmptyQueue(): void
    {
        $this->assertSame(BatchResult::Idle, $this->runReindexer());
        $this->assertSame([], $this->indexer->crawls, 'Nothing is crawled.');
        $this->assertSame([], $this->logger->records);
    }

    public function testCrawlsExactlyTheQueuedUrls(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->queue('https://nuorten.hel.fi/b/');

        $this->assertSame(BatchResult::Done, $this->runReindexer());
        $this->assertSame(['pending' => 0, 'processing' => 0], array_slice($this->queue->stats(), 0, 2));
        $info = $this->logger->messages('info');
        $this->assertMatchesRegularExpression('/^Processing batch \S+: 2 update\(s\), 0 delete\(s\)\.$/', $info[0]);
        $this->assertMatchesRegularExpression('/^Batch \S+ done\.$/', $info[1]);
        $this->assertSame([], $this->logger->messages('error'));

        $config = $this->crawlConfig();
        $this->assertSame(['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/b/'], $this->seeds());
        $domains = $config['domains'] ?? null;
        $this->assertIsArray($domains);
        $this->assertIsArray($domains[0]);
        unset($domains[0]['seed_urls']);
        $this->assertSame(
            [[
                'url' => 'https://nuorten.hel.fi',
                'crawl_rules' => [['policy' => 'deny', 'type' => 'begins', 'pattern' => '/admin']],
            ]],
            $domains,
            "Only the batch's sites, with their own settings, without sitemaps.",
        );
        $this->assertSame(1, $config['max_crawl_depth'] ?? null, 'No links are followed.');
        $this->assertTrue($config['sitemap_discovery_disabled'] ?? null);
        $this->assertFalse($config['purge_crawl_enabled'] ?? null, 'A purge would delete the rest of the index.');
        $this->assertArrayNotHasKey('schedule', $config);
    }

    public function testSkipsSitesRemovedFromTheConfig(): void
    {
        $this->queue('https://www.hel.fi/fi');
        $this->queue('https://avustukset.hel.fi/fi/x');

        $this->assertSame(BatchResult::Done, $this->runReindexer());
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

        $this->assertSame(BatchResult::Failed, $this->runReindexer());
        $this->assertSame(1, $this->pending(), 'The batch is returned to the queue.');
        $this->assertStringEndsWith('None of the URLs belong to a configured site.', $this->batchError());
    }

    public function testDeletesAndUpdates(): void
    {
        $this->queue('https://nuorten.hel.fi/a/', Action::Delete);
        $this->queue('https://nuorten.hel.fi/b/');
        $this->queue('https://nuorten.hel.fi/c/', Action::Delete);

        $this->assertSame(BatchResult::Done, $this->runReindexer());
        $this->assertCount(1, $this->indexer->deletes);
        $this->assertEqualsCanonicalizing(
            ['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/c/'],
            $this->indexer->deletes[0],
        );
        $this->assertSame(['https://nuorten.hel.fi/b/'], $this->seeds());
        $this->assertContains('Deleted 2 document(s).', $this->logger->messages('info'));
    }

    public function testLatestRequestForAUrlWins(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->queue('https://nuorten.hel.fi/a/', Action::Delete);

        $this->assertSame(BatchResult::Done, $this->runReindexer());
        $this->assertSame([['https://nuorten.hel.fi/a/']], $this->indexer->deletes);
        $this->assertSame([], $this->indexer->crawls);
    }

    public function testFailedCrawlReturnsTheBatch(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->indexer->failure = new \RuntimeException('1 page(s) failed to process.');

        $this->assertSame(BatchResult::Failed, $this->runReindexer());
        $this->assertSame(1, $this->pending());
        $this->assertStringContainsString('1 page(s) failed to process', $this->batchError());
    }

    public function testFailedDeleteReturnsTheBatch(): void
    {
        $this->queue('https://nuorten.hel.fi/a/', Action::Delete);
        $this->queue('https://nuorten.hel.fi/b/');
        $this->indexer->failure = new \RuntimeException('Elasticsearch is down.');

        $this->assertSame(BatchResult::Failed, $this->runReindexer());
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
        file_put_contents($this->configFile, "max_crawl_depth: 1\n");

        $this->assertSame(BatchResult::Failed, $this->runReindexer());
        $this->assertSame(1, $this->pending());
        $this->assertStringContainsString('No domains configured', $this->batchError());
    }

    public function testStopsWhenAsked(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->indexer->ticks = 1;

        $this->assertSame(BatchResult::Stopped, $this->runReindexer(static fn (): bool => true));
        $this->assertSame(1, $this->pending(), 'Back in the queue.');
        $this->assertSame([], $this->queue->failed());
        $this->assertSame([], $this->logger->messages('error'));
        $this->assertMatchesRegularExpression(
            '/^Batch \S+ was returned to the queue: the worker is stopping\.$/',
            $this->logger->messages('info')[1] ?? '',
        );
    }

    public function testKeepsRunningUntilAsked(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $this->indexer->ticks = 3;

        $this->assertSame(BatchResult::Done, $this->runReindexer(static fn (): bool => false));
    }

    public function testLease(): void
    {
        $this->queue('https://nuorten.hel.fi/a/');
        $batch = $this->queue->claim(10, 0, 10);
        $this->assertNotNull($batch);
        $lease = new Lease($this->queue, $batch, $this->logger, 10);
        $expires = $this->redis()->zscore($this->redisPrefix() . 'batches', $batch->id);

        // Renewed at most every second (a tenth of the lease).
        $lease->renew();
        $this->assertSame($expires, $this->redis()->zscore($this->redisPrefix() . 'batches', $batch->id));
        sleep(1);
        $lease->renew();
        $this->assertGreaterThan($expires, $this->redis()->zscore($this->redisPrefix() . 'batches', $batch->id));

        // Another worker takes over an expired batch.
        $this->queue->release($batch);
        sleep(1);
        $lease->renew();
        $lease->renew();
        $this->assertCount(1, $this->logger->messages('warning'), 'Logged once.');
        $this->assertStringContainsString('its lease expired', $this->logger->messages('warning')[0]);
    }
}
