<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\Action;
use App\Queue\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testJsonRoundTrip(): void
    {
        $request = new Request('https://nuorten.hel.fi/a/', Action::Delete, '2026-11-05T06:00:00+00:00');
        $json = $request->toJson();

        $this->assertSame(
            '{"url":"https://nuorten.hel.fi/a/","action":"delete","received_at":"2026-11-05T06:00:00+00:00"}',
            $json,
        );
        $this->assertEquals($request, Request::fromJson($json));
    }

    public function testNow(): void
    {
        $request = Request::now('https://nuorten.hel.fi/', Action::Update);

        $this->assertSame(Action::Update, $request->action);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $request->receivedAt));
    }

    public function testMissingReceivedAtIsAccepted(): void
    {
        $request = Request::fromJson('{"url":"https://nuorten.hel.fi/","action":"update"}');

        $this->assertNotNull($request);
        $this->assertSame('', $request->receivedAt);
    }

    #[DataProvider('invalidJsonData')]
    public function testInvalid(string $json): void
    {
        $this->assertNull(Request::fromJson($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJsonData(): array
    {
        return [
            'not json' => ['{bad'],
            'not an object' => ['"text"'],
            'no url' => ['{"action":"update"}'],
            'url not a string' => ['{"url":5,"action":"update"}'],
            'no action' => ['{"url":"https://nuorten.hel.fi/"}'],
            'unknown action' => ['{"url":"https://nuorten.hel.fi/","action":"purge"}'],
        ];
    }
}
