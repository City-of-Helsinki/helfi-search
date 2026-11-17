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

        return new Elasticsearch(new Client(['handler' => $stack, 'base_uri' => 'http://elastic:9200']));
    }

    public function testDeleteByUrls(): void
    {
        $es = $this->elasticsearch([new Response(200, [], '{"deleted":2}')]);

        $this->assertSame(2, $es->deleteByUrls('hel-fi', ['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/b/']));

        $request = $this->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/hel-fi/_delete_by_query', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['refresh' => 'true', 'conflicts' => 'proceed'], $query);

        $urls = ['https://nuorten.hel.fi/a/', 'https://nuorten.hel.fi/b/'];
        $this->assertSame(
            ['query' => ['bool' => [
                'should' => [
                    ['terms' => ['url' => $urls]],
                    // Documents stored under a canonical URL are found by the
                    // crawled URL too.
                    ['terms' => ['additional_urls' => $urls]],
                ],
                'minimum_should_match' => 1,
            ]]],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function testDeleteFromAMissingIndex(): void
    {
        $es = $this->elasticsearch([new Response(404)]);

        $this->assertSame(0, $es->deleteByUrls('hel-fi', ['https://nuorten.hel.fi/']));
    }

    public function testDeleteFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $this->elasticsearch([new Response(500, [], 'boom')])->deleteByUrls('hel-fi', ['https://nuorten.hel.fi/']);
    }

    public function testBulk(): void
    {
        $this->elasticsearch([new Response(200, [], '{"errors":false,"items":[]}')])->bulk([
            ['index' => ['_index' => 'hel-fi', '_id' => 'a']],
            ['url' => 'https://nuorten.hel.fi/ä'],
        ]);

        $request = $this->requests[0];
        $this->assertSame('/_bulk', $request->getUri()->getPath());
        $this->assertSame('application/x-ndjson', $request->getHeaderLine('Content-Type'));
        $this->assertSame(
            '{"index":{"_index":"hel-fi","_id":"a"}}' . "\n" . '{"url":"https://nuorten.hel.fi/ä"}' . "\n",
            (string) $request->getBody(),
        );
    }

    public function testBulkWithNothingToDo(): void
    {
        $this->elasticsearch([])->bulk([]);

        $this->assertSame([], $this->requests);
    }

    public function testBulkFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('1 bulk operation(s) failed, e.g. for b: {"type":"mapper_parsing_exception"}');
        $this->elasticsearch([new Response(200, [], json_encode(['errors' => true, 'items' => [
            ['index' => ['_id' => 'a', 'status' => 201]],
            ['index' => ['_id' => 'b', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception']]],
        ]], JSON_THROW_ON_ERROR))])->bulk([['delete' => ['_index' => 'x', '_id' => 'a']]]);
    }

    public function testMget(): void
    {
        $es = $this->elasticsearch([new Response(200, [], json_encode(['docs' => [
            ['_id' => 'a', 'found' => true, '_source' => ['content_hash' => 'x']],
            ['_id' => 'b', 'found' => false],
        ]], JSON_THROW_ON_ERROR))]);

        $this->assertSame(['a' => ['content_hash' => 'x']], $es->mget('hel-fi', ['a', 'b'], ['content_hash']));
        $this->assertSame('_source=content_hash', $this->requests[0]->getUri()->getQuery());
        $this->assertSame([], $this->elasticsearch([new Response(404)])->mget('missing', ['a']));
    }

    public function testScan(): void
    {
        $es = $this->elasticsearch([
            new Response(200, [], '{"id":"pit1"}'),
            new Response(200, [], json_encode(['pit_id' => 'pit2', 'hits' => ['hits' => [
                ['_id' => 'a', '_source' => ['n' => 1], 'sort' => [1]],
                ['_id' => 'b', '_source' => ['n' => 2], 'sort' => [2]],
            ]]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['pit_id' => 'pit2', 'hits' => ['hits' => [
                ['_id' => 'c', '_source' => ['n' => 3], 'sort' => [3]],
            ]]], JSON_THROW_ON_ERROR)),
            new Response(200, [], '{}'),
        ]);

        $docs = iterator_to_array($es->scan('hel-fi', ['match_all' => (object) []], true, batchSize: 2));

        $this->assertSame(['a' => ['n' => 1], 'b' => ['n' => 2], 'c' => ['n' => 3]], $docs);
        $second = json_decode((string) $this->requests[2]->getBody(), true);
        $this->assertIsArray($second);
        $this->assertSame([2], $second['search_after'] ?? null);
        $this->assertSame(['id' => 'pit2', 'keep_alive' => '5m'], $second['pit'] ?? null);
        $this->assertSame('DELETE', $this->requests[3]->getMethod(), 'The point in time is closed.');
    }

    public function testScanOfAMissingIndex(): void
    {
        $this->assertSame([], iterator_to_array($this->elasticsearch([new Response(404)])->scan('missing', [])));
    }

    public function testExists(): void
    {
        $es = $this->elasticsearch([new Response(200), new Response(404)]);

        $this->assertTrue($es->exists('hel-fi'));
        $this->assertFalse($es->exists('missing'));
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
