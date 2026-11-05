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
    /** @var \Closure(string, string): void */
    private readonly \Closure $output;

    /**
     * @param string $home The Open Crawler install directory.
     * @param (\Closure(string, string): void)|null $output Receives the
     *   crawler's output as it runs: the type (Process::OUT or Process::ERR)
     *   and the text. By default it goes to this process's stdout and stderr,
     *   i.e. the container log.
     */
    public function __construct(
        private readonly string $home = '/home/app',
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $output = null,
    ) {
        $this->output = $output ?? static function (string $type, string $text): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $text);
        };
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
            // Count rejected documents as the output streams past, instead of
            // reading it all at the end: a large failing crawl can log more
            // than PHP's memory limit.
            $counter = new FailedDocumentCounter();
            $process->run(function (string $type, string $text) use ($counter): void {
                ($this->output)($type, $text);
                $counter->read($type, $text);
            });
            if (!$process->isSuccessful()) {
                throw new \RuntimeException("The crawler exited with code {$process->getExitCode()}.");
            }

            $failed = $counter->failed();
            if ($failed > 0) {
                throw new \RuntimeException("$failed document(s) failed to index; see the crawler output above.");
            }
        } finally {
            $this->filesystem->remove($file);
        }
    }
}
