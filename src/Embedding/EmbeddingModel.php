<?php

declare(strict_types=1);

namespace App\Embedding;

/**
 * The embedding models. The search index has one chunk field per model.
 */
enum EmbeddingModel: string
{
    /** The model the pages are embedded with. */
    public const self DEFAULT = self::Large;

    case Large = 'text-embedding-3-large';

    public function dimensions(): int
    {
        return match ($this) {
            self::Large => 3072,
        };
    }

    /**
     * The search index field holding the chunks, e.g.
     * "embeddings_text_embedding_3_large", as in the helfi_search module.
     */
    public function field(): string
    {
        return 'embeddings_' . preg_replace('/[^a-z0-9]/', '_', $this->value);
    }
}
