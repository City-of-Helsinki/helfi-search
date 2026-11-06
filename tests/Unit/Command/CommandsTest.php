<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CrawlCommand;
use App\Command\WorkerCommand;
use App\CrawlerConfig;
use App\CrawlerConfigLoader;
use App\Kernel;
use App\Queue\Action;
use App\Queue\QueueFolder;
use App\Queue\QueueWriter;
use App\Queue\Request;
use App\Queue\Spool;
use App\Site;
use App\Tests\KernelEnvironmentTrait;
use App\Tests\Unit\CollectingLogger;
use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Worker\Elasticsearch;
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
#[UsesClass(Kernel::class)]
#[UsesClass(CrawlerConfig::class)]
#[UsesClass(CrawlerConfigLoader::class)]
#[UsesClass(Elasticsearch::class)]
#[UsesClass(QueueFolder::class)]
#[UsesClass(QueueWriter::class)]
#[UsesClass(Request::class)]
#[UsesClass(Spool::class)]
#[UsesClass(Reindexer::class)]
#[UsesClass(Site::class)]
final class CommandsTest extends KernelTestCase
{
    use TemporaryDirectoryTrait;
    use KernelEnvironmentTrait;

    private const string CONFIG = <<<'YAML'
        domains:
          - url: https://nuorten.hel.fi
        output_index: hel-fi
        elasticsearch:
          # Nothing listens here.
          host: http://127.0.0.1
          port: 1
        YAML;

    private string $queueDir;

    protected function setUp(): void
    {
        $this->queueDir = $this->temporaryDirectory() . '/queue';
        $configFile = $this->temporaryDirectory() . '/crawler.yml';
        file_put_contents($configFile, self::CONFIG);
        self::configureKernel($this->queueDir, $configFile);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find($name));
    }

    public function testWorkerWithAnEmptyQueue(): void
    {
        $this->assertSame(Command::SUCCESS, $this->command('app:worker')->execute([]));
        $this->assertSame(['Queue is empty.'], self::loggedMessages('info'));
    }

    public function testWorkerReturnsAFailedBatchAndFails(): void
    {
        // Deletes fail: Elasticsearch isn't reachable.
        (new QueueWriter(new QueueFolder($this->queueDir)))
            ->add(Request::now('https://nuorten.hel.fi/a', Action::Delete));

        $this->assertSame(Command::FAILURE, $this->command('app:worker')->execute([]));
        $this->assertCount(1, self::loggedMessages('error'));
        $this->assertCount(1, glob("$this->queueDir/incoming/*.json") ?: [], 'Back in the queue.');
    }

    public function testWorkerReleasesUnfinishedBatches(): void
    {
        $folder = new QueueFolder($this->queueDir);
        (new QueueWriter($folder))->add(Request::now('https://nuorten.hel.fi/a', Action::Delete));
        $spool = new Spool($folder, new CollectingLogger());
        $spool->claim();
        // Without a crawler or Elasticsearch, the released batch fails.
        $this->command('app:worker')->execute([]);

        $this->assertContains('Returned 1 unfinished change(s) to the queue.', self::loggedMessages('info'));
    }

    public function testOnlyOneWorkerRuns(): void
    {
        $spool = new Spool(new QueueFolder($this->queueDir), new CollectingLogger());
        $this->assertTrue($spool->lock());

        // flock() locks are per open file, so this process conflicts with
        // itself.
        $this->assertSame(Command::SUCCESS, $this->command('app:worker')->execute([]));
        $this->assertSame(['Another worker is running; nothing to do.'], self::loggedMessages('info'));
    }

    public function testWorkerRejectsAnInvalidInterval(): void
    {
        foreach (['0', '-1', 'soon'] as $every) {
            $tester = $this->command('app:worker');
            $this->assertSame(Command::INVALID, $tester->execute(['--every' => $every]), $every);
            $this->assertStringContainsString('--every must be', $tester->getDisplay());
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
}
