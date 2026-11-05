<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use Symfony\Component\Yaml\Yaml;

/**
 * A stand-in for Elastic Open Crawler's bin/crawler.
 *
 * It saves the config it was given (and the file's permissions), prints the
 * given output and exits with the given code. Uses TemporaryDirectoryTrait.
 */
trait FakeCrawlerTrait
{
    /**
     * @return string The fake crawler's home directory, for new Crawler($home).
     */
    private function fakeCrawler(
        string $output = "Number of documents that failed to index: 0\n",
        int $exitCode = 0,
    ): string {
        $home = $this->temporaryDirectory() . '/crawler';
        @mkdir("$home/bin", 0775, true);
        file_put_contents("$home/output.txt", $output);
        file_put_contents("$home/bin/crawler", <<<SH
            #!/bin/sh
            # Called as: bin/crawler crawl <config file>
            [ "\$1" = crawl ] || exit 64
            cp "\$2" "$home/received.yml"
            stat -c %a "\$2" > "$home/received.mode"
            cat "$home/output.txt"
            exit $exitCode
            SH);
        chmod("$home/bin/crawler", 0755);

        return $home;
    }

    private function receivedConfig(string $home): ?string
    {
        return is_file("$home/received.yml") ? (string) file_get_contents("$home/received.yml") : null;
    }

    /**
     * The config the crawler received, parsed.
     *
     * @return array<mixed>
     */
    private function parsedReceivedConfig(string $home): array
    {
        $config = Yaml::parse((string) $this->receivedConfig($home));
        $this->assertIsArray($config, 'The crawler was started.');

        return $config;
    }
}
