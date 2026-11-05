<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\CrawlerConfig;
use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Worker\Elasticsearch;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

#[CoversClass(Elasticsearch::class)]
#[UsesClass(CrawlerConfig::class)]
final class ElasticsearchTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /** @var list<RequestInterface> The requests sent, in order. */
    private array $requests = [];

    /**
     * @param list<Response> $responses
     */
    private function elasticsearch(array $responses): Elasticsearch
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->requests[] = $request;
            return $request;
        }));

        return new Elasticsearch(new Client(['handler' => $stack, 'base_uri' => 'http://elastic:9200']), 'hel-fi');
    }

    public function testDeleteByUrls(): void
    {
        $es = $this->elasticsearch([new Response(200, [], '{"deleted":2}')]);

        $this->assertSame(2, $es->deleteByUrls(['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/b/']));

        $request = $this->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/hel-fi/_delete_by_query', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['refresh' => 'true', 'conflicts' => 'proceed'], $query);

        $urls = ['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/b/'];
        $this->assertSame(
            ['query' => ['bool' => [
                'should' => [
                    ['terms' => ['url.keyword' => $urls]],
                    // Documents stored under a canonical URL are found by the
                    // crawled URL too.
                    ['terms' => ['additional_urls.keyword' => $urls]],
                ],
                'minimum_should_match' => 1,
            ]]],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function testDeleteFromAMissingIndex(): void
    {
        $this->assertSame(0, $this->elasticsearch([new Response(404)])->deleteByUrls(['https://nuorten.hel.fi/']));
    }

    public function testDeleteFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $this->elasticsearch([new Response(500, [], 'boom')])->deleteByUrls(['https://nuorten.hel.fi/']);
    }

    public function testWaitUntilReady(): void
    {
        $this->elasticsearch([new Response(200, [], '{"status":"yellow"}')])->waitUntilReady(5);

        $this->assertSame('/_cluster/health', $this->requests[0]->getUri()->getPath());
    }

    public function testWaitUntilReadyGivesUp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not ready within 0s');
        $this->elasticsearch([new Response(200, [], '{"status":"red"}')])->waitUntilReady(0);
    }

    /**
     * @param array<string, string> $environment
     */
    private function configuredClient(array $environment): Client
    {
        $file = $this->temporaryDirectory() . '/crawler.yml';
        file_put_contents($file, <<<'YAML'
            domains:
              - url: https://nuorten.hel.fi
            output_index: hel-fi
            elasticsearch:
              host: http://crawler-elastic
              port: 9200
            YAML);
        foreach ($environment as $variable => $value) {
            putenv("$variable=$value");
        }
        try {
            $es = Elasticsearch::fromConfig(CrawlerConfig::fromFile($file));
        } finally {
            foreach (array_keys($environment) as $variable) {
                putenv($variable);
            }
        }

        $client = (new \ReflectionProperty(Elasticsearch::class, 'client'))->getValue($es);
        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }

    /**
     * @return array<mixed>
     */
    private function headers(Client $client): array
    {
        $headers = $client->getConfig('headers');
        $this->assertIsArray($headers);

        return $headers;
    }

    public function testFromConfig(): void
    {
        $client = $this->configuredClient([]);

        $baseUri = $client->getConfig('base_uri');
        $this->assertInstanceOf(UriInterface::class, $baseUri);
        $this->assertSame('http://crawler-elastic:9200', (string) $baseUri);
        $this->assertArrayNotHasKey('Authorization', $this->headers($client));
        $this->assertNull($client->getConfig('auth'));
    }

    public function testFromConfigWithApiKey(): void
    {
        $client = $this->configuredClient(['ELASTICSEARCH_API_KEY' => 'secret']);

        $this->assertSame('ApiKey secret', $this->headers($client)['Authorization'] ?? null);
    }

    public function testFromConfigWithUsernameAndPassword(): void
    {
        $client = $this->configuredClient([
            'ELASTICSEARCH_USERNAME' => 'elastic',
            'ELASTICSEARCH_PASSWORD' => 'changeme',
        ]);

        $this->assertSame(['elastic', 'changeme'], $client->getConfig('auth'));
    }
}
