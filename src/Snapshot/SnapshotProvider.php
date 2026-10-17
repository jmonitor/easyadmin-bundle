<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Collects an instance's data behind the cache: each collector has its own lifetime,
 * a failure is cached for the dynamic lifetime so a dead service is not hammered.
 */
final readonly class SnapshotProvider
{
    public function __construct(
        private CollectorRunner $runner,
        private DeltaCalculator $deltas,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private int $pollingInterval,
        private int $staticTtl,
    ) {}

    public function get(Instance $instance): ComponentSnapshot
    {
        if (!$instance->isFound()) {
            return new ComponentSnapshot($instance->component, [], $instance->error);
        }

        $results = [];

        foreach ($instance->collectors->specs as $spec) {
            $results[$spec->name] = $this->result($spec, $instance->keyPrefix());
        }

        return new ComponentSnapshot($instance->component, $results);
    }

    public function refresh(Instance $instance): void
    {
        foreach ($instance->collectors?->names() ?? [] as $name) {
            $this->cache->deleteItem(self::cacheKey($name, $instance->keyPrefix()));
        }
    }

    public static function cacheKey(string $name, string $prefix = ''): string
    {
        return 'jmonitor_easyadmin.collect.' . $prefix . $name;
    }

    private static function describe(\Throwable $e): string
    {
        $cause = $e->getPrevious()?->getMessage();

        if ($cause === null || $cause === '' || $cause === $e->getMessage()) {
            return $e->getMessage();
        }

        return sprintf('%s (%s)', $e->getMessage(), $cause);
    }

    private function result(CollectorSpec $spec, string $prefix): CollectorResult
    {
        $item = $this->cache->getItem(self::cacheKey($spec->name, $prefix));

        if ($item->isHit() && CollectorResult::isValidArray($item->get())) {
            return CollectorResult::fromArray($spec->name, $item->get());
        }

        $now = $this->clock->now()->getTimestamp();

        try {
            $data = $this->runner->run($spec->collector);
            $error = null;
        } catch (\Throwable $e) {
            $data = [];
            $error = self::describe($e);
        }

        $delta = $error === null && $spec->trackDelta ? $this->deltas->track($prefix . $spec->name, $data, $now) : null;
        $result = new CollectorResult($spec->name, $data, $error, $now, $delta);
        $lifetime = $error === null ? $spec->lifetime : Lifetime::Dynamic;

        $item->set($result->toArray());
        $item->expiresAfter($lifetime->ttl($this->pollingInterval, $this->staticTtl));
        $this->cache->save($item);

        return $result;
    }
}
