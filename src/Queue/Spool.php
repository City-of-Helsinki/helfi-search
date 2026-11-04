<?php

declare(strict_types=1);

namespace App\Queue;

use App\Log;

/**
 * Takes requests from the queue folder in batches. Used by the worker.
 *
 * A batch moves the files it takes from incoming/ into processing/<batch>/,
 * so anything arriving during a crawl stays in incoming/ for the next batch.
 */
final class Spool
{
    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly QueueFolder $folder,
    ) {
        foreach ([$folder->incoming, $folder->processing, $folder->failed] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException("Cannot create $path.");
            }
        }
    }

    /**
     * Makes this process the only worker using the queue, until it exits.
     *
     * While we hold the lock, any batch left in processing/ belongs to a
     * worker that died, so it is safe to return it to the queue.
     *
     * This is a safety net, not the main guarantee: on network filesystems
     * (Azure Files/SMB, NFS) locks may not work, or only work within one
     * machine (e.g. SMB mounted with "nobrl"). The scheduler must not start
     * overlapping runs either (CronJob concurrencyPolicy: Forbid).
     *
     * @return bool FALSE if another worker holds the lock.
     */
    public function lock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }

        $lockFile = $this->folder->lockFile;
        $handle = fopen($lockFile, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open $lockFile.");
        }
        // The kernel releases the lock when the process ends, however it ends.
        if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if ($wouldBlock) {
                fclose($handle);
                return false;
            }
            // The filesystem doesn't support locking. Refusing to run would
            // stop all processing without anyone noticing, so carry on.
            Log::warning("Cannot lock $lockFile: the queue filesystem doesn't support locks. Make sure runs never overlap.");
        }

        $this->lock = $handle;
        return true;
    }

    /**
     * Moves every waiting request into a new batch.
     *
     * @return array{0: string, 1: array<string, Action>}
     *   The batch ID, and the action per URL. If a URL was queued more than
     *   once, the latest request wins.
     */
    public function claim(): array
    {
        $batch = gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(4));
        $batchDir = $this->batchDir($batch);

        // Names start with the arrival time, so this is arrival order.
        $files = glob($this->folder->incoming . '/' . QueueFolder::REQUEST_FILES) ?: [];
        sort($files);
        if (!$files) {
            return [$batch, []];
        }

        mkdir($batchDir, 0775);
        $actions = [];
        foreach ($files as $file) {
            $name = basename($file);
            $target = "$batchDir/$name";
            // rename() is atomic on one filesystem. A failure means the file
            // went away in the meantime; skip it.
            if (!@rename($file, $target)) {
                continue;
            }

            $request = Request::fromJson((string) file_get_contents($target));
            if ($request === null) {
                Log::warning("Moved unreadable request $name to failed/.");
                rename($target, $this->folder->failed . "/$name");
                continue;
            }

            // Re-adding moves the URL to the end, keeping arrival order.
            unset($actions[$request->url]);
            $actions[$request->url] = $request->action;
        }

        return [$batch, $actions];
    }

    /**
     * Removes a processed batch.
     */
    public function complete(string $batch): void
    {
        $batchDir = $this->batchDir($batch);
        foreach (glob("$batchDir/" . QueueFolder::REQUEST_FILES) ?: [] as $file) {
            unlink($file);
        }
        @rmdir($batchDir);
    }

    /**
     * Returns a failed batch to incoming/, so the next run retries it. The
     * file names are kept, so newer requests for the same URL still win.
     */
    public function release(string $batch): void
    {
        $batchDir = $this->batchDir($batch);
        foreach (glob("$batchDir/" . QueueFolder::REQUEST_FILES) ?: [] as $file) {
            rename($file, $this->folder->incoming . '/' . basename($file));
        }
        @rmdir($batchDir);
    }

    /**
     * Returns batches left by a worker that stopped before finishing them.
     *
     * @return int The number of returned requests.
     */
    public function releaseAll(): int
    {
        if ($this->lock === null) {
            throw new \LogicException('Call lock() first: another worker may be processing these batches.');
        }

        $count = 0;
        foreach (glob($this->folder->processing . '/*', GLOB_ONLYDIR) ?: [] as $batchDir) {
            $count += count(glob("$batchDir/" . QueueFolder::REQUEST_FILES) ?: []);
            $this->release(basename($batchDir));
        }

        return $count;
    }

    private function batchDir(string $batch): string
    {
        return $this->folder->processing . "/$batch";
    }
}
