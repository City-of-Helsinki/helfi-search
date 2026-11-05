<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Counts the documents Elasticsearch rejected, from the crawler's output as
 * it streams: the crawler reports success and exits with 0 even then, and
 * only its summary says so ("Number of documents that failed to index: <n>").
 *
 * Keeps only the count and an unfinished line, so memory use doesn't grow
 * with the output, however long the crawl.
 */
final class FailedDocumentCounter
{
    private const string PATTERN = '/Number of documents that failed to index: (\d+)/';

    /** A line can't be longer than this; the summary line is short. */
    private const int MAX_LINE = 65536;

    private int $failed = 0;

    /** @var array<string, string> The unfinished last line, per output stream. */
    private array $unfinished = [];

    /**
     * Reads a chunk of output, as it arrives.
     *
     * @param string $stream Which output it is (stdout or stderr): chunks of
     *   the two can interleave.
     */
    public function read(string $stream, string $chunk): void
    {
        $lines = explode("\n", ($this->unfinished[$stream] ?? '') . $chunk);
        $last = array_pop($lines);
        // Overlong lines can't hold the summary; keep only their end, in case
        // it starts there.
        $this->unfinished[$stream] = strlen($last) > self::MAX_LINE ? substr($last, -1024) : $last;

        foreach ($lines as $line) {
            $this->count($line);
        }
    }

    /**
     * The number of rejected documents, once the output has ended.
     */
    public function failed(): int
    {
        foreach ($this->unfinished as $line) {
            $this->count($line);
        }
        $this->unfinished = [];

        return $this->failed;
    }

    private function count(string $line): void
    {
        if (preg_match(self::PATTERN, $line, $matches) === 1) {
            $this->failed += (int) $matches[1];
        }
    }
}
