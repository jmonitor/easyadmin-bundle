<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Warns of a Doctrine connection the detection skips because its service throws. The detection
 * runs on every request, polling included, and each database detector walks every connection:
 * the cache keeps the warning to once per connection and error for the given lifetime. The connections
 * skipped by the current request are kept for the overview.
 */
final class SkippedConnectionReporter implements ResetInterface
{
    /** @var array<string, string> connection name => error */
    private array $skipped = [];

    /**
     * @param list<string> $shown connections configured for an enabled component, whose tab shows the error
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $lifetime,
        private readonly array $shown = [],
    ) {}

    public function report(string $connection, string $error): void
    {
        if (in_array($connection, $this->shown, true)) {
            return;
        }

        $this->skipped[$connection] = $error;

        // Called while detecting: a failing cache or logger must not cost the other connections.
        try {
            $item = $this->cache->getItem('jmonitor_easyadmin.skipped_connection.' . hash('xxh128', $connection . "\0" . $error));

            if ($item->isHit()) {
                return;
            }

            $this->logger->warning('Doctrine connection "{connection}" could not be read, the monitoring skips it: {error}', [
                'connection' => $connection,
                'error' => $error,
            ]);

            $this->cache->save($item->set(true)->expiresAfter($this->lifetime));
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, string> connection name => error
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function reset(): void
    {
        $this->skipped = [];
    }
}
