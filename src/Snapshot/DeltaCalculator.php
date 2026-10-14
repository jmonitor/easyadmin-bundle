<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps the previous sample of each collector in the cache and compares it with the new one.
 * A delta is only meaningful when the two samples are neither too close nor too far apart;
 * a sample too close to the previous one is dropped so the next one can still be compared.
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

        if (!is_array($previous) || !is_int($previous['timestamp'] ?? null) || !is_array($previous['values'] ?? null)) {
            $this->save($item, $current, $now);

            return null;
        }

        $elapsed = $now - $previous['timestamp'];

        if ($elapsed >= 0 && $elapsed < $this->minElapsed) {
            return null;
        }

        $this->save($item, $current, $now);

        if ($elapsed < $this->minElapsed || $elapsed > $this->maxElapsed) {
            return null;
        }

        return new Delta($previous['values'], $current, $elapsed);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(CacheItemInterface $item, array $values, int $now): void
    {
        $item->set(['timestamp' => $now, 'values' => $values]);
        $item->expiresAfter($this->maxElapsed * 2);
        $this->cache->save($item);
    }
}
