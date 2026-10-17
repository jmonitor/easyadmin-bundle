<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Warns of a Doctrine connection the detection skips because its service throws. The detection
 * runs on every request, polling included, and each database detector walks every connection:
 * the cache keeps the warning to once per connection and error for the given lifetime.
 */
final readonly class SkippedConnectionReporter
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private int $lifetime,
    ) {}

    public function report(string $connection, \Throwable $error): void
    {
        $item = $this->cache->getItem('jmonitor_easyadmin.skipped_connection.' . hash('xxh128', $connection . "\0" . $error->getMessage()));

        if ($item->isHit()) {
            return;
        }

        $this->logger->warning('Doctrine connection "{connection}" could not be read, the monitoring skips it: {error}', [
            'connection' => $connection,
            'error' => $error->getMessage(),
            'exception' => $error,
        ]);

        $this->cache->save($item->set(true)->expiresAfter($this->lifetime));
    }
}
