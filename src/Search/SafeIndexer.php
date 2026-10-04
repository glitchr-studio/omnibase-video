<?php

namespace Base\Video\Search;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerInterface;
use Typesense\Bundle\EventListener\TypesenseIndexer;

/**
 * glitchr/typesense-bundle's indexer, made safe for a search server that is
 * down: whatever the client throws (the bundle only catches its own and
 * php-http's exceptions - a PSR-18 network error from symfony/http-client
 * went through and failed the page that saved a film), the index misses a
 * change, the save does not fail and no visitor is told about Typesense.
 * `bin/console typesense:action upsert` fills the index again. It also
 * forgets what it has sent, so a long-running worker does not send it twice.
 */
class SafeIndexer extends TypesenseIndexer
{
    private ?LoggerInterface $logger = null;

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function postFlush(PostFlushEventArgs $args)
    {
        $transactions = $this->transactions;
        $this->transactions = [];
        $failed = false;
        foreach ($transactions as $transaction) {
            try {
                $transaction->commit();
            } catch (\Throwable $e) {
                if (!$failed) {
                    $this->logger?->warning('The search index missed a change (Typesense): {message}', ['message' => $e->getMessage()]);
                }
                $failed = true;
            }
        }
        if ($transactions) {
            try {
                $this->cache->clear();
            } catch (\Throwable) {
            }
        }
    }
}
