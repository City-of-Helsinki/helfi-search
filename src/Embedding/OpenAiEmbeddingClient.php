<?php

declare(strict_types=1);

namespace App\Embedding;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The OpenAI (or Azure OpenAI) embeddings API, as used by the helfi_search
 * module.
 *
 * OPENAI_BASE_URL is the API's base URL, with "{model}" standing for the
 * model: e.g. https://api.openai.com/v1, or for Azure
 * https://<resource>.openai.azure.com/openai/deployments/{model}.
 */
final class OpenAiEmbeddingClient implements EmbeddingClient
{
    /** The Azure API version; OpenAI ignores it. */
    public const string API_VERSION = '2024-10-21';

    /** Texts per request; the API accepts up to 2048. */
    private const int BATCH_SIZE = 256;

    /** Retries for rate limits (429) and server errors, with backoff. */
    private const int MAX_RETRIES = 5;

    private readonly ClientInterface $client;

    /**
     * @param ClientInterface|null $client For tests; the default retries
     *   rate-limited and failed requests.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
        ?ClientInterface $client = null,
        private readonly int $retryDelayMs = 1000,
    ) {
        $this->client = $client ?? new Client([
            'handler' => $this->retryingHandler(),
            RequestOptions::TIMEOUT => 120,
        ]);
    }

    public function embed(array $texts, EmbeddingModel $model): array
    {
        if ($this->apiKey === '' || $this->baseUrl === '') {
            throw new EmbeddingException('OPENAI_API_KEY and OPENAI_BASE_URL must be set.');
        }

        $vectors = [];
        foreach (array_chunk($texts, self::BATCH_SIZE, true) as $batch) {
            $vectors += $this->request($batch, $model);
        }

        return $vectors;
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, list<float>>
     */
    private function request(array $texts, EmbeddingModel $model): array
    {
        try {
            $response = $this->client->request(
                'POST',
                rtrim(str_replace('{model}', $model->value, $this->baseUrl), '/') . '/embeddings',
                [
                    RequestOptions::QUERY => ['api-version' => self::API_VERSION],
                    RequestOptions::HEADERS => ['Authorization' => "Bearer $this->apiKey"],
                    RequestOptions::JSON => ['model' => $model->value, 'input' => array_values($texts)],
                ],
            );
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException | \JsonException $e) {
            throw new EmbeddingException('The embeddings API failed: ' . $e->getMessage(), previous: $e);
        }

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if (!is_array($data) || count($data) !== count($texts)) {
            throw new EmbeddingException('The embeddings API returned an unexpected response.');
        }

        $vectors = [];
        foreach ($data as $item) {
            $index = is_array($item) ? ($item['index'] ?? null) : null;
            $embedding = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (!is_int($index) || !is_array($embedding) || count($embedding) !== $model->dimensions()) {
                throw new EmbeddingException('The embeddings API returned an unexpected response.');
            }
            $vectors[$index] = array_map(
                static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0,
                array_values($embedding),
            );
        }
        ksort($vectors);

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $this->logger->info('Embedded {count} text(s) with {model}: {tokens} token(s).', [
            'count' => count($texts),
            'model' => $model->value,
            'tokens' => is_int($usage['total_tokens'] ?? null) ? $usage['total_tokens'] : 0,
        ]);

        return array_combine(array_map('strval', array_keys($texts)), array_values($vectors));
    }

    /**
     * Retries 429 and 5xx responses and connection errors, waiting as the
     * Retry-After header says, or exponentially longer.
     */
    private function retryingHandler(): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $error): bool {
                if ($retries >= self::MAX_RETRIES) {
                    return false;
                }
                $status = $response?->getStatusCode() ?? 0;
                $retry = $error !== null || $status === 429 || $status >= 500;
                if ($retry) {
                    $this->logger->warning('The embeddings API failed ({reason}); retrying.', [
                        'reason' => $response?->getStatusCode() ?? $error?->getMessage(),
                    ]);
                }

                return $retry;
            },
            function (int $retries, ?ResponseInterface $response): int {
                $retryAfter = $response?->getHeaderLine('Retry-After') ?? '';
                if (ctype_digit($retryAfter)) {
                    return (int) $retryAfter * 1000;
                }

                return $this->retryDelayMs * 2 ** ($retries - 1);
            },
        ));

        return $stack;
    }
}
