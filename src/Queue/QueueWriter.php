<?php

declare(strict_types=1);

namespace App\Queue;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Adds requests to the queue folder. Used by the webhook.
 */
final class QueueWriter
{
    public function __construct(
        private readonly QueueFolder $folder,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function add(Request $request): void
    {
        // dumpFile() creates the folder if needed, writes a temporary file
        // (whose name doesn't end in .json) and renames it into place.
        $this->filesystem->dumpFile(
            $this->folder->incoming . '/' . QueueFolder::newFileName(),
            $request->toJson(),
        );
    }

    /**
     * Whether requests can be added: the incoming folder, or the nearest
     * existing folder above it, is writable. Missing folders are created with
     * the first request; on a fresh volume, none of them exist yet.
     */
    public function isWritable(): bool
    {
        $path = $this->folder->incoming;
        while (!is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        return is_writable($path);
    }

    /**
     * The number of waiting requests. A URL queued twice counts twice.
     */
    public function pendingCount(): int
    {
        return count(glob($this->folder->incoming . '/' . QueueFolder::REQUEST_FILES) ?: []);
    }
}
