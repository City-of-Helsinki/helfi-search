<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CrawlCommand;
use App\Command\QueueCommand;
use App\Command\WorkerCommand;
use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Kernel;
use App\Queue\Action;
use App\Queue\Batch;
use App\Queue\RedisClientFactory;
use App\Queue\RedisQueue;
use App\Site;
use App\Tests\KernelEnvironmentTrait;
use App\Tests\RedisTrait;
use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Worker\Elasticsearch;
use App\Worker\Lease;
use App\Worker\Reindexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The commands' options and the worker's flow. The crawling itself is tested
 * in ReindexerTest and the integration tests.
 */
#[CoversClass(WorkerCommand::class)]
#[CoversClass(CrawlCommand::class)]
#[CoversClass(QueueCommand::class)]
#[UsesClass(Kernel::class)]
#[UsesClass(Batch::class)]
#[UsesClass(CrawlerConfig::class)]
#[UsesClass(CrawlerConfigLoader::class)]
#[UsesClass(Elasticsearch::class)]
#[UsesClass(Lease::class)]
#[UsesClass(RedisClientFactory::class)]
#[UsesClass(RedisQueue::class)]
#[UsesClass(Reindexer::class)]
#[UsesClass(Site::class)]
final class CommandsTest extends KernelTestCase
{
    use TemporaryDirectoryTrait;
    use KernelEnvironmentTrait;
    use RedisTrait;

    private const string CONFIG = <<<'YAML'
        domains:
          - url: https://nuorten.hel.fi
          - url: https://avustukset.hel.fi
        elasticsearch:
          # Nothing listens here.
          host: http://127.0.0.1
          port: 1
        YAML;

    private RedisQueue $queue;

    protected function setUp(): void
    {
        $configFile = $this->temporaryDirectory() . '/crawler.yml';
        file_put_contents($configFile, self::CONFIG);
        self::configureKernel($configFile, $this->redisPrefix());
        $this->queue = new RedisQueue($this->redis(), $this->redisPrefix());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find($name));
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function worker(array $options = []): int
    {
        return $this->command('app:worker')->execute($options + [
            '--once' => true,
            '--settle' => '0',
            '--alive-file' => $this->temporaryDirectory() . '/alive',
        ]);
    }

    public function testWorkerWithAnEmptyQueue(): void
    {
        $this->assertSame(Command::SUCCESS, $this->worker());
        $this->assertSame(['Nothing left to claim.'], self::loggedMessages('info'));
        $this->assertFileExists($this->temporaryDirectory() . '/alive', 'For the liveness probe.');
        $this->assertCount(1, $this->queue->stats()['workers'], 'Seen.');
    }

    public function testWorkerReturnsAFailedBatchAndFails(): void
    {
        // Deletes fail: Elasticsearch isn't reachable.
        $this->queue->add('https://nuorten.hel.fi/a', Action::Delete);

        $this->assertSame(Command::FAILURE, $this->worker());
        $this->assertCount(1, self::loggedMessages('error'));
        $this->assertSame(1, $this->queue->stats()['pending'], 'Back in the queue.');
    }

    public function testWorkerWaitsForChangesToSettle(): void
    {
        $this->queue->add('https://nuorten.hel.fi/a', Action::Delete);

        $this->assertSame(Command::SUCCESS, $this->worker(['--settle' => '60']));
        $this->assertSame(1, $this->queue->stats()['pending'], 'Not claimed yet.');
    }

    public function testWorkerCannotReachRedis(): void
    {
        self::configureKernel(
            $this->temporaryDirectory() . '/crawler.yml',
            $this->redisPrefix(),
            redisUrl: 'redis://127.0.0.1:1',
        );

        $this->assertSame(Command::FAILURE, $this->worker());
        $this->assertStringStartsWith('Cannot claim changes: ', self::loggedMessages('error')[0] ?? '');
    }

    public function testWorkerRejectsInvalidOptions(): void
    {
        foreach (['batch-size' => '0', 'settle' => '-1', 'poll' => 'soon'] as $option => $value) {
            $tester = $this->command('app:worker');
            $this->assertSame(Command::INVALID, $tester->execute(["--$option" => $value]), $option);
            $this->assertStringContainsString("--$option must be", $tester->getDisplay());
        }
    }

    public function testCrawlRejectsAnInvalidLimit(): void
    {
        foreach (['-1', 'all'] as $limit) {
            $tester = $this->command('app:crawl');
            $this->assertSame(Command::INVALID, $tester->execute(['--limit-per-domain' => $limit]), $limit);
            $this->assertStringContainsString('--limit-per-domain must be', $tester->getDisplay());
        }
    }

    public function testCrawlRejectsAnUnknownSite(): void
    {
        foreach (['2', 'www.hel.fi'] as $site) {
            $tester = $this->command('app:crawl');
            $this->assertSame(Command::INVALID, $tester->execute(['--site' => $site]), $site);
            $this->assertStringContainsString("No site $site in crawler.yml.", $tester->getDisplay());
        }
    }

    public function testQueue(): void
    {
        $failing = new RedisQueue($this->redis(), $this->redisPrefix(), maxAttempts: 1);
        $failing->add('https://nuorten.hel.fi/a', Action::Delete);
        $batch = $failing->claim(10, 0, 60);
        $this->assertNotNull($batch);
        $failing->release($batch, 'Elasticsearch is down.');
        $this->queue->add('https://nuorten.hel.fi/b', Action::Update);

        $tester = $this->command('app:queue');
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Waiting\s+1\b/', $display);
        $this->assertMatchesRegularExpression('/Failed\s+1\b/', $display);
        $this->assertStringContainsString('https://nuorten.hel.fi/a', $display);
        $this->assertStringContainsString('Elasticsearch is down.', $display);

        $tester->execute(['--retry-failed' => true]);
        $this->assertStringContainsString('Queued 1 failed change(s) again.', $tester->getDisplay());
        $this->assertSame(2, $this->queue->stats()['pending']);
    }
}
