<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\CrawlerConfig;
use App\Site;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CrawlerConfig::class)]
#[UsesClass(Site::class)]
final class CrawlerConfigTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const array ENVIRONMENT = [
        'ELASTICSEARCH_HOST',
        'ELASTICSEARCH_PORT',
        'ELASTICSEARCH_API_KEY',
        'ELASTICSEARCH_USERNAME',
        'ELASTICSEARCH_PASSWORD',
    ];

    protected function setUp(): void
    {
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
    }

    private function clearEnvironment(): void
    {
        foreach (self::ENVIRONMENT as $variable) {
            putenv($variable);
        }
    }

    private function config(string $yaml): CrawlerConfig
    {
        $file = $this->temporaryDirectory() . '/crawler.yml';
        file_put_contents($file, $yaml);

        return CrawlerConfig::fromFile($file);
    }

    private const string YAML = <<<'YAML'
        domains:
          - url: https://nuorten.hel.fi
            exclude_tags: [header]
          - url: https://avustukset.hel.fi
        output_index: hel-fi
        elasticsearch:
          host: http://crawler-elastic/
          port: 9200
          pipeline: hel-fi
        YAML;

    public function testProjectConfigIsValid(): void
    {
        $config = CrawlerConfig::fromFile(dirname(__DIR__, 2) . '/config/crawler.yml');

        $this->assertNotEmpty($config->sites());
        $this->assertSame('hel-fi', $config->index());
    }

    public function testSites(): void
    {
        $this->assertSame(
            ['https://nuorten.hel.fi', 'https://avustukset.hel.fi'],
            $this->config(self::YAML)->sites(),
        );
    }

    public function testDomainFor(): void
    {
        $config = $this->config(self::YAML);

        $this->assertSame('https://nuorten.hel.fi', $config->domainFor('https://nuorten.hel.fi/opiskelu/'));
        $this->assertSame('https://avustukset.hel.fi', $config->domainFor('HTTPS://Avustukset.hel.fi:443/fi'));
        $this->assertNull($config->domainFor('https://www.hel.fi/fi'));
        $this->assertNull($config->domainFor('http://nuorten.hel.fi/'), 'The scheme must match.');
    }

    public function testElasticsearchFromFile(): void
    {
        $config = $this->config(self::YAML);

        $this->assertSame('http://crawler-elastic:9200', $config->elasticsearchUrl());
        $this->assertSame([], $config->elasticsearchCredentials());
        $this->assertSame('hel-fi', $config->index());
    }

    public function testElasticsearchDefaults(): void
    {
        $config = $this->config("domains:\n  - url: https://nuorten.hel.fi\noutput_index: x\n");

        $this->assertSame('http://localhost:9200', $config->elasticsearchUrl());
    }

    public function testEnvironmentOverrides(): void
    {
        putenv('ELASTICSEARCH_HOST=https://elastic.example');
        putenv('ELASTICSEARCH_PORT=443');
        putenv('ELASTICSEARCH_API_KEY=secret-key');
        $config = $this->config(self::YAML);

        $this->assertSame('https://elastic.example:443', $config->elasticsearchUrl());
        $this->assertSame(['api_key' => 'secret-key'], $config->elasticsearchCredentials());

        $elasticsearch = $config->toArray()['elasticsearch'];
        $this->assertIsArray($elasticsearch);
        $this->assertSame(443, $elasticsearch['port'] ?? null, 'The port is an integer.');
        $this->assertSame('secret-key', $elasticsearch['api_key'] ?? null, 'The crawler gets the overrides too.');
        $this->assertSame('hel-fi', $elasticsearch['pipeline'] ?? null, 'Other settings are kept.');
    }

    public function testEmptyEnvironmentVariablesAreIgnored(): void
    {
        putenv('ELASTICSEARCH_HOST=');
        $config = $this->config(self::YAML);

        $this->assertSame('http://crawler-elastic:9200', $config->elasticsearchUrl());
    }

    public function testUsernameAndPassword(): void
    {
        putenv('ELASTICSEARCH_USERNAME=elastic');
        putenv('ELASTICSEARCH_PASSWORD=changeme');

        $this->assertSame(
            ['username' => 'elastic', 'password' => 'changeme'],
            $this->config(self::YAML)->elasticsearchCredentials(),
        );
    }

    public function testApiKeyTakesPrecedence(): void
    {
        putenv('ELASTICSEARCH_API_KEY=key');
        putenv('ELASTICSEARCH_USERNAME=elastic');
        putenv('ELASTICSEARCH_PASSWORD=changeme');

        $this->assertSame(['api_key' => 'key'], $this->config(self::YAML)->elasticsearchCredentials());
    }

    public function testDomainsBySite(): void
    {
        $domains = $this->config(self::YAML)->domains();

        $this->assertSame(['https://nuorten.hel.fi', 'https://avustukset.hel.fi'], array_keys($domains));
        $this->assertSame(
            ['url' => 'https://nuorten.hel.fi', 'exclude_tags' => ['header']],
            $domains['https://nuorten.hel.fi'],
        );
    }

    public function testDomainWithoutUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs a url');
        $this->config("domains:\n  - exclude_tags: [header]\noutput_index: hel-fi\n");
    }

    public function testElasticsearchMustBeAMapping(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('elasticsearch in');
        $this->config("domains:\n  - url: https://nuorten.hel.fi\nelasticsearch: http://elastic\n");
    }

    public function testInvalidPort(): void
    {
        $config = $this->config("domains:\n  - url: https://nuorten.hel.fi\nelasticsearch:\n  port: nine\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('host or port is invalid');
        $config->elasticsearchUrl();
    }

    public function testNoDomains(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No domains configured');
        $this->config("output_index: hel-fi\n");
    }

    public function testNoIndex(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output_index is not set.');
        $this->config("domains:\n  - url: https://nuorten.hel.fi\n")->index();
    }
}
