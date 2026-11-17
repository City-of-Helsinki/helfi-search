<?php

declare(strict_types=1);

namespace App\Indexer;

use App\Pipeline\Chunk;

/**
 * A page turned into a search document, before its chunks are embedded.
 */
final readonly class ProcessedPage
{
    /**
     * @param array<string, mixed> $fields The search document's fields,
     *   without the chunks and the processing times.
     * @param array<string, Chunk> $chunks By cache key (see
     *   EmbeddingCache::key()), in page order.
     * @param string $contentHash Changes when anything in the document would
     *   change: the fields, the chunks or the pipeline version.
     */
    public function __construct(
        public Page $page,
        public array $fields,
        public array $chunks,
        public string $contentHash,
    ) {
    }
}
