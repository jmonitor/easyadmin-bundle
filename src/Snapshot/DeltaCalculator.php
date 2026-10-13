<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps the previous sample of each collector in the cache and compares it with the new one.
 * A delta is only meaningful when the two samples are neither too close nor too far apart.
 */
final readonly class DeltaCalculator
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $minElapsed,
        private int $maxElapsed = 300,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function track(string $name, array $data, int $now): ?Delta
    {
        $item = $this->cache->getItem('jmonitor_easyadmin.previous.' . $name);
        $previous = $item->get();
        $current = Delta::flatten($data);

        $item->set(['timestamp' => $now, 'values' => $current]);
        $item->expiresAfter($this->maxElapsed * 2);
        $this->cache->save($item);

        if (!is_array($previous) || !isset($previous['timestamp'], $previous['values'])) {
            return null;
        }

        $elapsed = $now - $previous['timestamp'];

        if ($elapsed < $this->minElapsed || $elapsed > $this->maxElapsed) {
            return null;
        }

        return new Delta($previous['values'], $current, $elapsed);
    }
}
