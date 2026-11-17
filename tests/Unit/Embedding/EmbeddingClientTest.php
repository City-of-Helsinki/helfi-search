<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\Embedding\EmbeddingClientFactory;
use App\Embedding\EmbeddingException;
use App\Embedding\EmbeddingModel;
use App\Embedding\FakeEmbeddingClient;
use App\Embedding\OpenAiEmbeddingClient;
use App\Embedding\Vector;
use App\Tests\Unit\CollectingLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(OpenAiEmbeddingClient::class)]
#[CoversClass(FakeEmbeddingClient::class)]
#[CoversClass(EmbeddingClientFactory::class)]
#[CoversClass(EmbeddingModel::class)]
#[CoversClass(Vector::class)]
final class EmbeddingClientTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function client(array $responses, string $apiKey = 'key'): OpenAiEmbeddingClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->requests[] = $request;
            return $request;
        }));

        return new OpenAiEmbeddingClient(
            $apiKey,
            'https://example.openai.azure.com/openai/deployments/{model}/',
            $this->logger,
            new Client(['handler' => $stack]),
        );
    }

    /**
     * An API response with the given vectors, in reverse order: the API may
     * return them in any order.
     *
     * @param list<list<float>> $vectors
     */
    private static function response(array $vectors): Response
    {
        $data = [];
        foreach ($vectors as $index => $vector) {
            $data[] = ['index' => $index, 'embedding' => $vector];
        }

        return new Response(200, [], json_encode([
            'data' => array_reverse($data),
            'usage' => ['total_tokens' => 42],
        ], JSON_THROW_ON_ERROR));
    }

    public function testEmbed(): void
    {
        $a = array_fill(0, 3072, 0.5);
        $b = array_fill(0, 3072, -0.25);

        $vectors = $this->client([self::response([$a, $b])])
            ->embed(['x' => 'First', 'y' => 'Second'], EmbeddingModel::Large);

        $this->assertSame(['x' => $a, 'y' => $b], $vectors);
        $request = $this->requests[0];
        $this->assertSame(
            'https://example.openai.azure.com/openai/deployments/text-embedding-3-large/embeddings'
                . '?api-version=2024-10-21',
            (string) $request->getUri(),
        );
        $this->assertSame('Bearer key', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['model' => 'text-embedding-3-large', 'input' => ['First', 'Second']],
            json_decode((string) $request->getBody(), true),
        );
        $this->assertSame(
            ['Embedded 2 text(s) with text-embedding-3-large: 42 token(s).'],
            $this->logger->messages('info'),
        );
    }

    public function testRequiresAKey(): void
    {
        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        $this->client([], apiKey: '')->embed(['x' => 'Text'], EmbeddingModel::Large);
    }

    public function testApiError(): void
    {
        $this->expectException(EmbeddingException::class);
        $this->client([new Response(400, [], '{"error":"bad"}')])->embed(['x' => 'Text'], EmbeddingModel::Large);
    }

    public function testUnexpectedResponse(): void
    {
        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('unexpected response');
        $this->client([self::response([[0.1, 0.2]])])->embed(['x' => 'Text'], EmbeddingModel::Large);
    }

    public function testRetriesRateLimits(): void
    {
        $vector = array_fill(0, 3072, 0.5);
        $client = new OpenAiEmbeddingClient('key', 'https://api.openai.com/v1', $this->logger, retryDelayMs: 1);
        $handler = new MockHandler([new Response(429), new Response(503), self::response([$vector])]);
        // The default client, with a mock transport.
        $guzzle = (new \ReflectionProperty(OpenAiEmbeddingClient::class, 'client'))->getValue($client);
        $this->assertInstanceOf(Client::class, $guzzle);
        $stack = $guzzle->getConfig('handler');
        $this->assertInstanceOf(HandlerStack::class, $stack);
        $stack->setHandler($handler);

        $this->assertSame(['x' => $vector], $client->embed(['x' => 'Text'], EmbeddingModel::Large));
        $this->assertCount(2, $this->logger->messages('warning'));
    }

    public function testFakeVectors(): void
    {
        $fake = new FakeEmbeddingClient();
        $vectors = $fake->embed(['a' => 'Text', 'b' => 'Text', 'c' => 'Other'], EmbeddingModel::Large);

        $this->assertCount(3072, $vectors['a']);
        $this->assertSame($vectors['a'], $vectors['b'], 'The same text, the same vector.');
        $this->assertNotSame($vectors['a'], $vectors['c']);
        $length = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vectors['a'])));
        $this->assertEqualsWithDelta(1.0, $length, 1e-9, 'Unit length.');
        $this->assertSame(3, $fake->embedded);
    }

    public function testFactory(): void
    {
        $this->assertInstanceOf(
            FakeEmbeddingClient::class,
            EmbeddingClientFactory::create('', '', 'dev', $this->logger),
        );
        $this->assertInstanceOf(
            OpenAiEmbeddingClient::class,
            EmbeddingClientFactory::create('', '', 'prod', $this->logger),
            'Production never uses made-up vectors.',
        );
        $this->assertInstanceOf(
            OpenAiEmbeddingClient::class,
            EmbeddingClientFactory::create('key', 'url', 'dev', $this->logger),
        );
    }

    public function testModel(): void
    {
        $this->assertSame('embeddings_text_embedding_3_large', EmbeddingModel::Large->field());
        $this->assertSame(3072, EmbeddingModel::DEFAULT->dimensions());
    }

    public function testVectorEncoding(): void
    {
        $vector = [0.5, -0.25, 1.0];

        $this->assertSame($vector, Vector::decode(Vector::encode($vector)));
        $this->assertSame([], Vector::decode('not base64!'));
    }
}
