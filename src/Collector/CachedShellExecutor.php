<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\ShellExecutor;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps the outputs of the commands in one cache item per collector, so a static value such as
 * a version is not fetched by a new process at every poll. A failure is kept too, and logged when
 * the command runs, since the cached item hides it until a Refresh.
 */
final class CachedShellExecutor extends ShellExecutor
{
    private readonly ShellExecutor $inner;
    private readonly string $key;

    /**
     * @param string $name the collector, prefixed like SnapshotProvider's keys for a named instance
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl,
        string $name,
        LoggerInterface $logger = new NullLogger(),
        ?ShellExecutor $inner = null,
    ) {
        $this->inner = new LoggingShellExecutor($logger, $inner);
        $this->key = 'jmonitor_easyadmin.shell.' . $name;
    }

    public function execute(string $command): ?string
    {
        $item = $this->cache->getItem($this->key);
        $outputs = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        if (array_key_exists($command, $outputs) && ($outputs[$command] === null || is_string($outputs[$command]))) {
            return $outputs[$command];
        }

        $output = $this->inner->execute($command);
        $outputs[$command] = $output;
        // The commands of a collector share the item, so a late one renews the others.
        $item->set($outputs);
        $item->expiresAfter($this->ttl);
        $this->cache->save($item);

        return $output;
    }

    public function forget(): void
    {
        $this->cache->deleteItem($this->key);
    }
}
