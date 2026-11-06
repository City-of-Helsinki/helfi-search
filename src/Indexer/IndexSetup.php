<?php

declare(strict_types=1);

namespace App\Indexer;

use App\CrawlerConfig;
use App\Worker\Elasticsearch;
use Psr\Log\LoggerInterface;

/**
 * Creates the indexes that don't exist yet. Existing ones are left alone.
 */
final readonly class IndexSetup
{
    public function __construct(
        private Indexes $indexes,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \RuntimeException If an index can't be created.
     */
    public function run(Elasticsearch $elasticsearch, CrawlerConfig $config): void
    {
        $this->create($elasticsearch, $config->index(), Indexes::pagesMapping());
        $this->create($elasticsearch, $this->indexes->embeddingCache, Indexes::embeddingCacheMapping());

        $alias = $this->indexes->search;
        if ($elasticsearch->exists($alias)) {
            $this->logger->info('{index} exists.', ['index' => $alias]);
            return;
        }
        $index = $this->indexes->searchIndexVersion();
        $this->create($elasticsearch, $index, Indexes::searchMapping() + ['aliases' => [$alias => (object) []]]);
    }

    /**
     * @param array<mixed> $body
     */
    private function create(Elasticsearch $elasticsearch, string $index, array $body): void
    {
        if ($elasticsearch->exists($index)) {
            $this->logger->info('{index} exists.', ['index' => $index]);
            return;
        }

        $elasticsearch->request('PUT', "/$index", $body);
        $this->logger->info('Created {index}.', ['index' => $index]);
    }
}
