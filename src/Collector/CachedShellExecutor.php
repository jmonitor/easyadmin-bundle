<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\ShellExecutor;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps the outputs of the commands in one cache item per collector, so a static value such as
 * a version is not fetched by a new process at every poll. An empty output (binary not found,
 * process failure) is kept too, and logged, since the cached item hides it until the next run.
 */
final class CachedShellExecutor extends ShellExecutor
{
    private readonly ShellExecutor $inner;
    private readonly string $key;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl,
        string $name,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ShellExecutor $inner = null,
    ) {
        $this->inner = $inner ?? new ShellExecutor();
        $this->key = 'jmonitor_easyadmin.shell.' . $name;
    }

    public function execute(string $command): ?string
    {
        $item = $this->cache->getItem($this->key);
        $outputs = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        if (array_key_exists($command, $outputs) && ($outputs[$command] === null || is_string($outputs[$command]))) {
            return $outputs[$command];
        }

        try {
            $output = $this->inner->execute($command);

            if ($output === null) {
                $this->logger->warning(sprintf('"%s" gave no output: the binary may be missing from the PATH of the web process.', $command));
            }
        } catch (\Throwable $e) {
            // No proc_open, or a process that cannot start: the value is decorative.
            $this->logger->warning(sprintf('Cannot run "%s": %s', $command, $e->getMessage()));
            $output = null;
        }

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
