<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\RefreshableCollectorInterface;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Collects an instance's data behind the cache: each collector has its own lifetime,
 * a failure is cached for the dynamic lifetime so a dead service is not hammered, an
 * unreachable server for at least a minute since each attempt may wait for a timeout.
 * One request at a time collects an instance: the others do not pile up on the same timeout.
 */
final readonly class SnapshotProvider
{
    public const IN_PROGRESS = 'Another request is collecting these metrics, they show on the next refresh.';

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

        $prefix = $instance->keyPrefix();
        $lockKey = self::lockKey($instance);
        $locked = null;
        $results = [];

        try {
            foreach ($instance->collectors->specs as $spec) {
                $result = $this->cached($spec, $prefix);

                if ($result === null) {
                    $locked ??= $this->lock($lockKey);
                    $result = $locked
                        ? $this->collect($spec, $prefix)
                        : new CollectorResult($spec->name, [], self::IN_PROGRESS, $this->clock->now()->getTimestamp());
                }

                $results[$spec->name] = $result;
            }
        } finally {
            if ($locked === true) {
                $this->cache->deleteItem($lockKey);
            }
        }

        return new ComponentSnapshot($instance->component, $results);
    }

    public function refresh(Instance $instance): void
    {
        foreach ($instance->collectors->specs ?? [] as $spec) {
            $this->cache->deleteItem(self::cacheKey($spec->name, $instance->keyPrefix()));

            if ($spec->collector instanceof RefreshableCollectorInterface) {
                $spec->collector->refresh();
            }
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

    private static function lockKey(Instance $instance): string
    {
        return 'jmonitor_easyadmin.collecting.' . ($instance->id === null ? $instance->component->value : rtrim($instance->keyPrefix(), '.'));
    }

    private static function isUnreachable(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof UnreachableServerException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best effort, the cache offers no atomic write: two requests may still both collect.
     * The marker expires on its own when the request holding it dies.
     */
    private function lock(string $key): bool
    {
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            return false;
        }

        $this->cache->save($item->set(true)->expiresAfter($this->unreachableTtl()));

        return true;
    }

    private function unreachableTtl(): int
    {
        return max(Lifetime::Minute->ttl($this->pollingInterval, $this->staticTtl), Lifetime::Dynamic->ttl($this->pollingInterval, $this->staticTtl));
    }

    private function cached(CollectorSpec $spec, string $prefix): ?CollectorResult
    {
        $values = $this->cache->getItem(self::cacheKey($spec->name, $prefix))->get();

        return CollectorResult::isValidArray($values) ? CollectorResult::fromArray($spec->name, $values) : null;
    }

    private function collect(CollectorSpec $spec, string $prefix): CollectorResult
    {
        $now = $this->clock->now()->getTimestamp();

        try {
            $data = $this->runner->run($spec->collector);
            $error = null;
            $ttl = $spec->lifetime->ttl($this->pollingInterval, $this->staticTtl);
        } catch (\Throwable $e) {
            $data = [];
            $error = self::describe($e);
            $ttl = self::isUnreachable($e) ? $this->unreachableTtl() : Lifetime::Dynamic->ttl($this->pollingInterval, $this->staticTtl);
        }

        $delta = $error === null && $spec->trackDelta ? $this->deltas->track($prefix . $spec->name, $data, $now) : null;
        $result = new CollectorResult($spec->name, $data, $error, $now, $delta);
        $item = $this->cache->getItem(self::cacheKey($spec->name, $prefix));

        $item->set($result->toArray());
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        return $result;
    }
}
