<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Queue\Action;
use App\Queue\QueueFolder;
use App\Queue\QueueWriter;
use App\Queue\Request as QueueRequest;
use App\Site;
use App\Tests\Unit\CollectingLogger;
use App\Tests\Unit\TemporaryDirectoryTrait;
use App\Webhook\Webhook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(Webhook::class)]
#[UsesClass(QueueWriter::class)]
#[UsesClass(QueueFolder::class)]
#[UsesClass(QueueRequest::class)]
#[UsesClass(Site::class)]
final class WebhookTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string TOKEN = 'test-token';

    private QueueFolder $folder;
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->folder = new QueueFolder($this->temporaryDirectory() . '/queue');
        $this->logger = new CollectingLogger();
    }

    private function webhook(string $token = self::TOKEN): Webhook
    {
        return new Webhook(
            new QueueWriter($this->folder),
            $this->logger,
            $token,
            ['https://nuorten.hel.fi', 'https://avustukset.hel.fi'],
        );
    }

    /**
     * @return array{int, array<string, mixed>}
     *   The status code and the decoded JSON body.
     */
    private function send(
        string $method,
        string $path,
        ?string $body = null,
        ?string $token = self::TOKEN,
        string $configuredToken = self::TOKEN,
    ): array {
        $server = $token === null ? [] : ['HTTP_AUTHORIZATION' => "Bearer $token"];
        $request = Request::create($path, $method, server: $server, content: $body);
        $response = $this->webhook($configuredToken)->handle($request);

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $json = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        /** @var array<string, mixed> $json */
        return [$response->getStatusCode(), $json];
    }

    /**
     * @return list<QueueRequest>
     */
    private function queued(): array
    {
        $files = glob($this->folder->incoming . '/' . QueueFolder::REQUEST_FILES) ?: [];
        sort($files);

        return array_map(
            fn (string $file): QueueRequest => QueueRequest::fromJson((string) file_get_contents($file))
                ?? throw new \RuntimeException("Unreadable request file $file."),
            $files,
        );
    }

    public function testQueuesAnUpdate(): void
    {
        [$status, $body] = $this->send('POST', '/webhook', '{"url":"https://nuorten.hel.fi/opiskelu/"}');

        $this->assertSame(Response::HTTP_ACCEPTED, $status);
        $this->assertSame(['queued' => ['url' => 'https://nuorten.hel.fi/opiskelu/', 'action' => 'update']], $body);
        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $this->assertSame('https://nuorten.hel.fi/opiskelu/', $queued[0]->url);
        $this->assertSame(Action::Update, $queued[0]->action);
        $this->assertSame(['Queued update: https://nuorten.hel.fi/opiskelu/'], $this->logger->messages('info'));
    }

    public function testQueuesADelete(): void
    {
        [$status] = $this->send('POST', '/webhook', '{"url":"https://avustukset.hel.fi/fi/x","action":"delete"}');

        $this->assertSame(Response::HTTP_ACCEPTED, $status);
        $this->assertSame(Action::Delete, $this->queued()[0]->action);
    }

    public function testDropsTheFragment(): void
    {
        [, $body] = $this->send('POST', '/webhook', '{"url":"https://nuorten.hel.fi/a/#top"}');

        $this->assertSame(['url' => 'https://nuorten.hel.fi/a/', 'action' => 'update'], $body['queued'] ?? null);
    }

    public function testAcceptsDefaultPortAndCase(): void
    {
        [$status] = $this->send('POST', '/webhook', '{"url":"HTTPS://Nuorten.hel.fi:443/a/"}');

        $this->assertSame(Response::HTTP_ACCEPTED, $status);
    }

    #[DataProvider('invalidRequestData')]
    public function testRejectsInvalidRequests(string $body, int $expectedStatus): void
    {
        [$status, $response] = $this->send('POST', '/webhook', $body);

        $this->assertSame($expectedStatus, $status);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame([], $this->queued());
        $this->assertSame([], $this->logger->records);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function invalidRequestData(): array
    {
        return [
            'not json' => ['nope', 400],
            'no url' => ['{"action":"update"}', 400],
            'url not a string' => ['{"url":5}', 400],
            'unknown action' => ['{"url":"https://nuorten.hel.fi/","action":"purge"}', 400],
            'action not a string' => ['{"url":"https://nuorten.hel.fi/","action":5}', 400],
            'relative url' => ['{"url":"/opiskelu/"}', 400],
            'not http' => ['{"url":"ftp://nuorten.hel.fi/"}', 400],
            'unknown site' => ['{"url":"https://www.hel.fi/fi"}', 422],
            'other port' => ['{"url":"https://nuorten.hel.fi:8443/"}', 422],
            'other scheme' => ['{"url":"http://nuorten.hel.fi/"}', 422],
        ];
    }

    public function testRequiresTheToken(): void
    {
        $this->assertSame(401, $this->send('POST', '/webhook', '{"url":"https://nuorten.hel.fi/"}', token: null)[0]);
        $this->assertSame(401, $this->send('POST', '/webhook', '{"url":"https://nuorten.hel.fi/"}', token: 'wrong')[0]);
        $this->assertSame(401, $this->send('GET', '/status', token: null)[0]);
        $this->assertSame([], $this->queued());
    }

    public function testRefusesEverythingWithoutAConfiguredToken(): void
    {
        [$status, $body] = $this->send(
            'POST',
            '/webhook',
            '{"url":"https://nuorten.hel.fi/"}',
            token: '',
            configuredToken: '',
        );

        $this->assertSame(503, $status);
        $this->assertSame('WEBHOOK_TOKEN is not set.', $body['error']);
    }

    public function testStatus(): void
    {
        $this->send('POST', '/webhook', '{"url":"https://nuorten.hel.fi/a/"}');
        usleep(10);
        $this->send('POST', '/webhook', '{"url":"https://nuorten.hel.fi/a/"}');

        $this->assertSame([200, ['pending' => 2]], $this->send('GET', '/status'));
    }

    public function testHealth(): void
    {
        $this->assertSame([200, ['status' => 'ok']], $this->send('GET', '/health', token: null), 'No token needed.');
        $this->assertSame(200, $this->send('HEAD', '/health', token: null)[0]);
        $this->assertSame(405, $this->send('POST', '/health', token: null)[0]);
    }

    public function testHealthFailsWhenTheQueueIsNotWritable(): void
    {
        chmod($this->temporaryDirectory(), 0555);

        $this->assertSame(503, $this->send('GET', '/health', token: null)[0]);
    }

    #[DataProvider('wrongRouteData')]
    public function testWrongRoutes(string $method, string $path, int $expectedStatus): void
    {
        $this->assertSame($expectedStatus, $this->send($method, $path)[0]);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function wrongRouteData(): array
    {
        return [
            'GET /webhook' => ['GET', '/webhook', 405],
            'POST /status' => ['POST', '/status', 405],
            'unknown path' => ['GET', '/nope', 404],
            'front controller' => ['GET', '/index.php', 404],
        ];
    }
}
