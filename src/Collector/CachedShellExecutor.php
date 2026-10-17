<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\ShellExecutor;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps the output of a command in the cache pool, so a static value such as a version is not
 * fetched by a new process at every poll. An empty output (binary not found, process failure) is kept too.
 */
final class CachedShellExecutor extends ShellExecutor
{
    private readonly ShellExecutor $inner;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl,
        ?ShellExecutor $inner = null,
    ) {
        $this->inner = $inner ?? new ShellExecutor();
    }

    public function execute(string $command): ?string
    {
        $item = $this->cache->getItem('jmonitor_easyadmin.shell.' . md5($command));
        $cached = $item->get();

        if ($item->isHit() && is_array($cached) && array_key_exists('output', $cached) && ($cached['output'] === null || is_string($cached['output']))) {
            return $cached['output'];
        }

        try {
            $output = $this->inner->execute($command);
        } catch (\Throwable) {
            // No proc_open, or a process that cannot start: the version is decorative.
            $output = null;
        }

        $item->set(['output' => $output]);
        $item->expiresAfter($this->ttl);
        $this->cache->save($item);

        return $output;
    }
}
