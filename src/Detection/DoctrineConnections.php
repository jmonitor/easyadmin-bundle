<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Doctrine connections and the parameters the detectors read from them, kept in the cache:
 * with a few hundred connections, building them all on each request costs more than the page.
 * A connection that cannot be built is tried again after $errorLifetime seconds, the others after
 * $lifetime seconds, a new container build or forget().
 */
final class DoctrineConnections implements ResetInterface
{
    private const CACHE_KEY = 'jmonitor_easyadmin.doctrine_connections.';

    /** Only what the detectors read: the credentials never reach the cache. */
    private const PARAMS = ['driver', 'driverClass', 'dbname', 'host', 'port', 'unix_socket'];

    /** @var ?array<string, array{params: ?array<string, mixed>, error: ?string, until: int}> */
    private ?array $entries = null;

    /**
     * @param ContainerInterface $connections Doctrine connections, keyed by connection name
     * @param list<string>       $names       every connection, in Doctrine order
     * @param string             $version     changes with the container, so that a deployment reads the connections again
     */
    public function __construct(
        private readonly ContainerInterface $connections,
        private readonly array $names = [],
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?ClockInterface $clock = null,
        private readonly int $lifetime = 3600,
        private readonly int $errorLifetime = 60,
        private readonly string $version = '',
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names, true);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, array<string, mixed>|string> name => parameters without credentials, or why the connection could not be built
     */
    public function read(array $names): array
    {
        $entries = $this->entries ??= $this->load();
        $now = $this->now();
        $changed = false;
        $read = [];

        foreach ($names as $name) {
            $entry = $entries[$name] ?? null;

            // each entry keeps its own expiry: saving the item for another one must not extend it
            if ($entry === null || $entry['until'] <= $now) {
                $entries[$name] = $entry = $this->describe($name, $now);
                $changed = true;
            }

            $read[$name] = $entry['params'] ?? (string) $entry['error'];
        }

        if ($changed) {
            $this->entries = $entries;
            $this->cache?->save($this->cache->getItem($this->cacheKey())->set($entries)->expiresAfter($this->lifetime));
        }

        return $read;
    }

    public function get(string $name): Connection
    {
        /** @var Connection */
        return $this->connections->get($name);
    }

    public function forget(): void
    {
        $this->entries = null;
        $this->cache?->deleteItem($this->cacheKey());
    }

    public function reset(): void
    {
        $this->entries = null;
    }

    /**
     * @return array<string, array{params: ?array<string, mixed>, error: ?string, until: int}>
     */
    private function load(): array
    {
        $cached = $this->cache?->getItem($this->cacheKey())->get();

        if (!is_array($cached)) {
            return [];
        }

        $entries = [];

        foreach ($cached as $name => $entry) {
            if (!is_array($entry) || !is_int($entry['until'] ?? null)) {
                continue;
            }

            if (is_array($entry['params'] ?? null)) {
                $entries[(string) $name] = ['params' => $entry['params'], 'error' => null, 'until' => $entry['until']];
            } elseif (is_string($entry['error'] ?? null)) {
                $entries[(string) $name] = ['params' => null, 'error' => $entry['error'], 'until' => $entry['until']];
            }
        }

        return $entries;
    }

    /**
     * @return array{params: ?array<string, mixed>, error: ?string, until: int}
     */
    private function describe(string $name, int $now): array
    {
        try {
            return ['params' => self::strip($this->get($name)->getParams()), 'error' => null, 'until' => $now + $this->lifetime];
        } catch (\Throwable $e) {
            return ['params' => null, 'error' => self::message($e), 'until' => $now + $this->errorLifetime];
        }
    }

    /**
     * Only the message is cached: it carries the cause, which DoctrineBundle often wraps.
     */
    private static function message(\Throwable $e): string
    {
        $cause = $e->getPrevious()?->getMessage();

        if ($cause === null || $cause === '' || $cause === $e->getMessage()) {
            return $e->getMessage();
        }

        return sprintf('%s (%s)', $e->getMessage(), $cause);
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function strip(array $params): array
    {
        $kept = [];

        foreach (self::PARAMS as $key) {
            if (is_scalar($params[$key] ?? null)) {
                $kept[$key] = $params[$key];
            }
        }

        if (is_array($params['primary'] ?? null)) {
            $kept['primary'] = self::strip($params['primary']);
        }

        return $kept;
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY . hash('xxh128', $this->version);
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
