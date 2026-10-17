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
 * The configured connections are the instances, each one in error when its service is unknown or
 * its DSN invalid. Without any, one implicit instance comes from the Redis provider of the Symfony
 * cache (a DSN or a service id), then from the REDIS_URL environment variable. A DSN is opened
 * lazily so detection never waits on the network; a dead server shows up as a collect error in
 * the tab.
 */
final readonly class RedisDetector implements ComponentDetectorInterface
{
    /**
     * @param array<string|int, array{dsn: ?string, service: ?string}> $configured connection id => options
     */
    public function __construct(
        private ContainerInterface $services,
        private array $configured,
        private ?string $frameworkProvider,
        private ?string $envUrl,
    ) {}

    public function component(): Component
    {
        return Component::Redis;
    }

    public function detect(): Detection
    {
        if ($this->configured !== []) {
            $instances = [];

            foreach ($this->configured as $id => $options) {
                $instances[] = $this->instance((string) $id, $options);
            }

            return Detection::instances($instances);
        }

        if ($this->frameworkProvider !== null && $this->frameworkProvider !== '') {
            if (self::isDsn($this->frameworkProvider)) {
                return $this->fromDsn($this->frameworkProvider);
            }

            if (!$this->services->has($this->frameworkProvider)) {
                return Detection::unavailable(sprintf('The Redis provider of the Symfony cache is the service "%s", which is not available to the bundle.', $this->frameworkProvider));
            }

            return Detection::found(self::collectors(new RedisCollector($this->services->get($this->frameworkProvider))));
        }

        if ($this->envUrl !== null && $this->envUrl !== '') {
            return $this->fromDsn($this->envUrl);
        }

        return Detection::unavailable('No Redis DSN found: set components.redis.connections, framework.cache.default_redis_provider or the REDIS_URL env var.');
    }

    public static function isDsn(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1;
    }

    /**
     * The server of a DSN, without its credentials: host, port and database.
     */
    public static function detail(string $dsn): ?string
    {
        $parts = parse_url($dsn);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $detail = $parts['host'];

        if (isset($parts['port'])) {
            $detail .= ':' . $parts['port'];
        }

        $path = $parts['path'] ?? '';

        if (strlen($path) > 1) {
            $detail .= '/' . substr($path, 1);
        }

        return $detail;
    }

    /**
     * @param array{dsn: ?string, service: ?string} $options
     */
    private function instance(string $id, array $options): Instance
    {
        if ($options['service'] !== null) {
            if (!$this->services->has($options['service'])) {
                return Instance::unavailable(Component::Redis, sprintf('Unknown Redis service "%s".', $options['service']), $id);
            }

            try {
                $client = $this->services->get($options['service']);
            } catch (\Throwable $e) {
                return Instance::unavailable(Component::Redis, $e->getMessage(), $id);
            }

            return Instance::found(Component::Redis, self::collectors(new RedisCollector($client)), $id);
        }

        $dsn = $options['dsn'];

        if ($dsn === null || $dsn === '') {
            return Instance::unavailable(Component::Redis, 'Empty Redis DSN.', $id);
        }

        try {
            $collector = new RedisCollector(self::lazy($dsn));
        } catch (\Throwable $e) {
            return Instance::unavailable(Component::Redis, sprintf('Invalid Redis DSN: %s', $e->getMessage()), $id, self::detail($dsn));
        }

        return Instance::found(Component::Redis, self::collectors($collector), $id, self::detail($dsn));
    }

    private function fromDsn(string $dsn): Detection
    {
        try {
            $collector = new RedisCollector(self::lazy($dsn));
        } catch (\Throwable $e) {
            return Detection::unavailable(sprintf('Invalid Redis DSN: %s', $e->getMessage()));
        }

        return Detection::found(self::collectors($collector));
    }

    private static function collectors(RedisCollector $collector): CollectorSet
    {
        return new CollectorSet(Component::Redis, [
            new CollectorSpec($collector, Lifetime::Dynamic, trackDelta: true),
        ]);
    }

    private static function lazy(string $dsn): string
    {
        return $dsn . (str_contains($dsn, '?') ? '&' : '?') . 'lazy=1';
    }
}
