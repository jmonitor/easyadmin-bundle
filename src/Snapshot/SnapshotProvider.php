<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Collects a component's data behind the cache: each collector has its own lifetime,
 * a failure is cached for the dynamic lifetime so a dead service is not hammered.
 */
final readonly class SnapshotProvider
{
    public function __construct(
        private ComponentRegistry $registry,
        private CollectorRunner $runner,
        private DeltaCalculator $deltas,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private int $pollingInterval,
        private int $staticTtl,
    ) {}

    public function get(Component $component): ComponentSnapshot
    {
        $set = $this->registry->collectorsFor($component);

        if ($set === null) {
            return new ComponentSnapshot($component, [], $this->registry->errorFor($component) ?? 'This component is not available.');
        }

        $results = [];

        foreach ($set->specs as $spec) {
            $results[$spec->name] = $this->result($spec);
        }

        return new ComponentSnapshot($component, $results);
    }

    public function refresh(Component $component): void
    {
        foreach ($this->registry->collectorsFor($component)?->names() ?? [] as $name) {
            $this->cache->deleteItem(self::cacheKey($name));
        }
    }

    public static function cacheKey(string $name): string
    {
        return 'jmonitor_easyadmin.collect.' . $name;
    }

    private function result(CollectorSpec $spec): CollectorResult
    {
        $item = $this->cache->getItem(self::cacheKey($spec->name));

        if ($item->isHit() && is_array($item->get())) {
            return CollectorResult::fromArray($spec->name, $item->get());
        }

        $now = $this->clock->now()->getTimestamp();

        try {
            $data = $this->runner->run($spec->collector);
            $error = null;
        } catch (\Throwable $e) {
            $data = [];
            $error = $e->getMessage();
        }

        $delta = $error === null && $spec->trackDelta ? $this->deltas->track($spec->name, $data, $now) : null;
        $result = new CollectorResult($spec->name, $data, $error, $now, $delta);
        $lifetime = $error === null ? $spec->lifetime : Lifetime::Dynamic;

        $item->set($result->toArray());
        $item->expiresAfter($lifetime->ttl($this->pollingInterval, $this->staticTtl));
        $this->cache->save($item);

        return $result;
    }
}
