<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * The layout of the shared queue folder: the whole interface between the
 * webhook and the worker.
 *
 *   incoming/     Waiting requests, one file each (QueueWriter adds them)
 *   processing/   One subfolder per batch being processed (Spool)
 *   failed/       Unreadable request files, kept for inspection
 *   worker.lock   The worker's lock
 *
 * A request file is incoming/<16-digit microseconds>-<random hex>.json, with
 * the content described in Request. Names start with the arrival time, so
 * sorting them gives arrival order, and the latest request for a URL wins.
 * Files are written under a temporary name that doesn't end in .json and
 * then renamed, so the worker never reads a partial file.
 *
 * The folder is var/queue in the project (the app.queue_dir parameter):
 * /app/var/queue in the images, where OpenShift mounts the shared volume. The
 * rest of var/ is Symfony's cache, which stays in the image. Locally it's in
 * the mounted project folder (ignored by git).
 *
 * The webhook and the worker may run as different UIDs (OpenShift assigns
 * random ones). They share the folder through its group: both create entries
 * group-writable (umask 0002, set by the webhook's base image and by
 * bin/console), and the folder should be setgid (OpenShift's fsGroup does that
 * for volumes; the images do it for /app/var/queue).
 */
final readonly class QueueFolder
{
    /** Matches request files, but not files still being written. */
    public const string REQUEST_FILES = '*.json';

    public string $incoming;
    public string $processing;
    public string $failed;
    public string $lockFile;

    public function __construct(string $dir)
    {
        $dir = rtrim($dir, '/');
        $this->incoming = "$dir/incoming";
        $this->processing = "$dir/processing";
        $this->failed = "$dir/failed";
        $this->lockFile = "$dir/worker.lock";
    }

    /**
     * A new, unique request file name that sorts after earlier ones.
     */
    public static function newFileName(): string
    {
        return sprintf('%016d-%s.json', (int) (microtime(true) * 1_000_000), bin2hex(random_bytes(4)));
    }
}
