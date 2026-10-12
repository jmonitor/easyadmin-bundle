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
 * Symfony cache (a DSN or a service id), the REDIS_URL environment variable. A DSN is opened
 * lazily so detection never waits on the network; a dead server shows up as a collect error
 * in the tab.
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
            return $this->fromService($this->service, sprintf('Unknown Redis service "%s".', $this->service));
        }

        if ($this->dsn !== null && $this->dsn !== '') {
            return $this->fromDsn($this->dsn);
        }

        if ($this->frameworkProvider !== null && $this->frameworkProvider !== '') {
            if (self::isDsn($this->frameworkProvider)) {
                return $this->fromDsn($this->frameworkProvider);
            }

            return $this->fromService($this->frameworkProvider, sprintf('The Redis provider of the Symfony cache is the service "%s", which is not available to the bundle.', $this->frameworkProvider));
        }

        if ($this->envUrl !== null && $this->envUrl !== '') {
            return $this->fromDsn($this->envUrl);
        }

        return Detection::unavailable('No Redis DSN found: set components.redis.dsn, framework.cache.default_redis_provider or the REDIS_URL env var.');
    }

    private function fromService(string $id, string $missingMessage): Detection
    {
        if (!$this->services->has($id)) {
            return Detection::unavailable($missingMessage);
        }

        return $this->found(new RedisCollector($this->services->get($id)));
    }

    private function fromDsn(string $dsn): Detection
    {
        try {
            $collector = new RedisCollector(self::lazy($dsn));
        } catch (\Throwable $e) {
            return Detection::unavailable(sprintf('Invalid Redis DSN: %s', $e->getMessage()));
        }

        return $this->found($collector);
    }

    public static function isDsn(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1;
    }

    private function found(RedisCollector $collector): Detection
    {
        return Detection::found(new CollectorSet(Component::Redis, [
            new CollectorSpec($collector, Lifetime::Dynamic, trackDelta: true),
        ]));
    }

    private static function lazy(string $dsn): string
    {
        return $dsn . (str_contains($dsn, '?') ? '&' : '?') . 'lazy=1';
    }
}
