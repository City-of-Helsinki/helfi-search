<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Worker\Crawler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Crawler::class)]
final class CrawlerTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use FakeCrawlerTrait;

    private const array CONFIG = [
        'domains' => [['url' => 'https://nuorten.hel.fi', 'seed_urls' => ['https://nuorten.hel.fi/a/']]],
        'output_index' => 'hel-fi',
        'elasticsearch' => ['host' => 'http://crawler-elastic', 'port' => 9200, 'api_key' => 'secret'],
    ];

    /** @var list<array{string, string}> What the crawler printed: type and text. */
    private array $output = [];

    private function crawl(string $home): void
    {
        $collect = function (string $type, string $text): void {
            $this->output[] = [$type, $text];
        };
        (new Crawler($home, output: $collect))->crawl(self::CONFIG);
    }

    public function testPassesTheConfigAsYaml(): void
    {
        $home = $this->fakeCrawler();
        $this->crawl($home);

        $this->assertSame(self::CONFIG, Yaml::parse((string) $this->receivedConfig($home)));
        $this->assertSame('600', trim((string) file_get_contents("$home/received.mode")), 'It may hold credentials.');
    }

    public function testPassesOnTheCrawlersOutput(): void
    {
        $this->crawl($this->fakeCrawler("Crawling...\nNumber of documents that failed to index: 0\n"));

        $this->assertSame(
            "Crawling...\nNumber of documents that failed to index: 0\n",
            implode('', array_column($this->output, 1)),
        );
        $this->assertSame([Process::OUT], array_values(array_unique(array_column($this->output, 0))));
    }

    public function testRemovesTheTemporaryConfig(): void
    {
        $before = glob(sys_get_temp_dir() . '/crawl-*') ?: [];
        $this->crawl($this->fakeCrawler());

        $this->assertSame($before, glob(sys_get_temp_dir() . '/crawl-*') ?: []);
    }

    public function testFailsWhenTheCrawlerFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The crawler exited with code 3.');
        $this->crawl($this->fakeCrawler('', 3));
    }

    public function testFailsWhenDocumentsWereRejected(): void
    {
        // The crawler reports success and exits with 0 even then.
        $output = "Result: success\n- Failed\n  - Number of documents that failed to index: 2\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2 document(s) failed to index');
        $this->crawl($this->fakeCrawler($output));
    }
}
