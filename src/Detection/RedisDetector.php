<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Psr\Container\ContainerInterface;

/**
 * Resolution order: the configured service, the configured DSN, the Redis provider of the
 * Symfony cache, the REDIS_URL environment variable. A DSN is opened lazily so detection
 * never waits on the network; a dead server shows up as a collect error in the tab.
 */
final readonly class RedisDetector implements ComponentDetectorInterface
{
    public function __construct(
        private ContainerInterface $services,
        private ?string $service,
        private ?string $dsn,
        private ?string $frameworkProvider,
        private ?string $envUrl,
    ) {}

    public function component(): Component
    {
        return Component::Redis;
    }

    public function detect(): Detection
    {
        if ($this->service !== null) {
            if (!$this->services->has($this->service)) {
                return Detection::unavailable(sprintf('Unknown Redis service "%s".', $this->service));
            }

            return $this->found(new RedisCollector($this->services->get($this->service)));
        }

        $dsn = $this->firstNonEmpty($this->dsn, $this->frameworkProvider, $this->envUrl);

        if ($dsn === null) {
            return Detection::unavailable('No Redis DSN found: set components.redis.dsn, framework.cache.default_redis_provider or the REDIS_URL env var.');
        }

        try {
            $collector = new RedisCollector(self::lazy($dsn));
        } catch (\Throwable $e) {
            return Detection::unavailable(sprintf('Invalid Redis DSN: %s', $e->getMessage()));
        }

        return $this->found($collector);
    }

    private function found(RedisCollector $collector): Detection
    {
        return Detection::found(new CollectorSet(Component::Redis, [
            new CollectorSpec($collector, Lifetime::Dynamic, trackDelta: true),
        ]));
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private static function lazy(string $dsn): string
    {
        return $dsn . (str_contains($dsn, '?') ? '&' : '?') . 'lazy=1';
    }
}
