<?php

declare(strict_types=1);

namespace App\Worker;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs Elastic Open Crawler (bin/crawler) with a given config.
 */
final class Crawler
{
    /**
     * @param string $home The Open Crawler install directory.
     */
    public function __construct(
        private readonly string $home = '/home/app',
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Runs one crawl and waits for it to finish.
     *
     * @param array<string, mixed> $config A complete crawler config, in the
     *   same structure as crawler.yml.
     *
     * @throws \RuntimeException If the crawler fails, or any page fails to
     *   index.
     */
    public function crawl(array $config): void
    {
        $file = $this->filesystem->tempnam(sys_get_temp_dir(), 'crawl-', '.yml');
        $this->filesystem->dumpFile($file, Yaml::dump($config, 10, 2));
        // It may hold Elasticsearch credentials: owner only.
        $this->filesystem->chmod($file, 0600);

        try {
            $process = new Process(['bin/crawler', 'crawl', $file], $this->home, timeout: null);
            // Pass the crawler's output straight to this container's log.
            $process->run(function (string $type, string $output): void {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
            });
            if (!$process->isSuccessful()) {
                throw new \RuntimeException("The crawler exited with code {$process->getExitCode()}.");
            }

            // The crawler reports success and exits with 0 even when
            // Elasticsearch rejects every document, so check its summary.
            $failed = self::failedDocuments($process->getOutput());
            if ($failed > 0) {
                throw new \RuntimeException("$failed document(s) failed to index; see the crawler output above.");
            }
        } finally {
            $this->filesystem->remove($file);
        }
    }

    /**
     * Reads the number of failed documents from the crawler's summary:
     * "Number of documents that failed to index: <n>".
     */
    private static function failedDocuments(string $output): int
    {
        preg_match_all('/Number of documents that failed to index: (\d+)/', $output, $matches);

        return array_sum(array_map('intval', $matches[1]));
    }
}
