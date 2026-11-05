<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Site;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Site::class)]
final class SiteTest extends TestCase
{
    #[DataProvider('originData')]
    public function testOrigin(string $url, string $expected): void
    {
        $this->assertSame($expected, Site::origin($url));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function originData(): array
    {
        return [
            'path and query dropped' => ['https://nuorten.hel.fi/opiskelu/?a=1#top', 'https://nuorten.hel.fi'],
            'lowercased' => ['HTTPS://Nuorten.Hel.Fi/', 'https://nuorten.hel.fi'],
            'default https port dropped' => ['https://nuorten.hel.fi:443/x', 'https://nuorten.hel.fi'],
            'default http port dropped' => ['http://nuorten.hel.fi:80/x', 'http://nuorten.hel.fi'],
            'other port kept' => ['https://nuorten.hel.fi:8443/x', 'https://nuorten.hel.fi:8443'],
            'scheme matters' => ['http://nuorten.hel.fi', 'http://nuorten.hel.fi'],
        ];
    }
}
