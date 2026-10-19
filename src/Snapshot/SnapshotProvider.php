<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\RefreshableCollectorInterface;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Collects an instance's data behind the cache: each collector has its own lifetime,
 * a failure is cached for the dynamic lifetime so a dead service is not hammered, an
 * unreachable server for at least a minute since each attempt may wait for a timeout.
 * One request at a time collects an instance: the others do not pile up on the same timeout.
 * The connections of one server share the results and deltas of its server-wide collectors.
 */
final readonly class SnapshotProvider
{
    public const IN_PROGRESS = 'Another request is collecting these metrics, they show on the next refresh.';

    /**
     * Outlasts most connection timeouts: a lock expiring while its request still waits would let another
     * request start the same wait. Only the lock of a request that died lasts that long.
     */
    private const LOCK_TTL = 300;

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
        $token = null;
        $results = [];

        try {
            foreach ($instance->collectors->specs as $spec) {
                $serverPrefix = self::serverPrefix($instance, $spec);
                $serverItem = $serverPrefix === null ? null : $this->cache->getItem(self::cacheKey($spec->name, $serverPrefix));
                $item = $this->cache->getItem(self::cacheKey($spec->name, $prefix));
                $cached = self::cached($spec, $serverItem) ?? self::cached($spec, $item);

                if ($cached !== null) {
                    $results[$spec->name] = $cached;

                    continue;
                }

                $token ??= $this->lock($lockKey);
                $results[$spec->name] = $token === false
                    ? new CollectorResult($spec->name, [], self::IN_PROGRESS, $this->clock->now()->getTimestamp())
                    : $this->collect($spec, $serverPrefix ?? $prefix, $serverItem ?? $item, $item);
            }
        } finally {
            if (is_string($token)) {
                $this->unlock($lockKey, $token);
            }
        }

        return new ComponentSnapshot($instance->component, $results);
    }

    /**
     * Also lifts the lock: the request holding it may have died without releasing it.
     */
    public function refresh(Instance $instance): void
    {
        foreach ($instance->collectors->specs ?? [] as $spec) {
            $this->cache->deleteItem(self::cacheKey($spec->name, $instance->keyPrefix()));
            $serverPrefix = self::serverPrefix($instance, $spec);

            if ($serverPrefix !== null) {
                $this->cache->deleteItem(self::cacheKey($spec->name, $serverPrefix));
            }

            if ($spec->collector instanceof RefreshableCollectorInterface) {
                $spec->collector->refresh();
            }
        }

        $this->cache->deleteItem(self::lockKey($instance));
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

    /**
     * The connections of one server share its lock: they would all wait on the same timeout.
     */
    private static function lockKey(Instance $instance): string
    {
        $serverPrefix = $instance->serverKeyPrefix();

        if ($serverPrefix !== null) {
            return 'jmonitor_easyadmin.collecting.' . rtrim($serverPrefix, '.');
        }

        return 'jmonitor_easyadmin.collecting.' . ($instance->id === null ? $instance->component->value : rtrim($instance->keyPrefix(), '.'));
    }

    private static function serverPrefix(Instance $instance, CollectorSpec $spec): ?string
    {
        return $spec->perServer ? $instance->serverKeyPrefix() : null;
    }

    private static function cached(CollectorSpec $spec, ?CacheItemInterface $item): ?CollectorResult
    {
        $values = $item?->get();

        return CollectorResult::isValidArray($values) ? CollectorResult::fromArray($spec->name, $values) : null;
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
     *
     * @return string|false the token proving the lock is ours, false when another request holds it
     */
    private function lock(string $key): string|false
    {
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            return false;
        }

        $token = bin2hex(random_bytes(8));
        $this->cache->save($item->set($token)->expiresAfter(max(self::LOCK_TTL, $this->unreachableTtl())));

        return $token;
    }

    /**
     * Leaves alone a lock taken over by another request after ours expired.
     */
    private function unlock(string $key, string $token): void
    {
        if ($this->cache->getItem($key)->get() === $token) {
            $this->cache->deleteItem($key);
        }
    }

    private function unreachableTtl(): int
    {
        return max(Lifetime::Minute->ttl($this->pollingInterval, $this->staticTtl), Lifetime::Dynamic->ttl($this->pollingInterval, $this->staticTtl));
    }

    /**
     * A failure is kept apart from a shared success: it may come from the privileges of one connection only.
     */
    private function collect(CollectorSpec $spec, string $deltaPrefix, CacheItemInterface $successItem, CacheItemInterface $failureItem): CollectorResult
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

        $delta = $error === null && $spec->trackDelta ? $this->deltas->track($deltaPrefix . $spec->name, $data, $now) : null;
        $result = new CollectorResult($spec->name, $data, $error, $now, $delta);
        $item = $error === null ? $successItem : $failureItem;

        $item->set($result->toArray());
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        return $result;
    }
}
