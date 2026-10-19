<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\EasyAdminBundle\Detection\DoctrineConnections;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cache\RecordingCachePool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DoctrineConnectionsTest extends TestCase
{
    private RecordingCachePool $cache;
    private MockClock $clock;

    /** @var array<string, int> connection name => times built */
    private array $built = [];

    protected function setUp(): void
    {
        $this->cache = new RecordingCachePool();
        $this->clock = new MockClock('2026-10-19 10:00:00');
    }

    public function testReadsTheParametersWithoutTheCredentials(): void
    {
        $connections = $this->connections([
            'default' => ['driver' => 'pdo_mysql', 'host' => 'db1', 'port' => 3307, 'dbname' => 'shop', 'user' => 'app', 'password' => 'secret'],
            'replicated' => ['driver' => 'pdo_mysql', 'primary' => ['host' => 'db2', 'dbname' => 'shop', 'user' => 'app', 'password' => 'secret'], 'replica' => [['host' => 'db3', 'password' => 'secret']]],
        ]);

        $read = $connections->read(['default', 'replicated']);

        self::assertSame(['driver' => 'pdo_mysql', 'dbname' => 'shop', 'host' => 'db1', 'port' => 3307], $read['default']);
        self::assertSame(['driver' => 'pdo_mysql', 'primary' => ['dbname' => 'shop', 'host' => 'db2']], $read['replicated']);
        self::assertStringNotContainsString('secret', serialize($this->cache->getItem($this->cacheKey())->get()));
    }

    public function testTheCacheSparesTheNextRequestsBuildingTheConnections(): void
    {
        $params = ['default' => ['driver' => 'pdo_mysql', 'dbname' => 'shop'], 'reporting' => ['driver' => 'pdo_mysql', 'dbname' => 'reports']];

        $this->connections($params)->read(['default', 'reporting']);
        $read = $this->connections($params)->read(['default', 'reporting']);

        self::assertSame(['default' => 1, 'reporting' => 1], $this->built);
        self::assertSame('reports', $read['reporting']['dbname'] ?? null);
        self::assertSame(3600, $this->cache->ttls[$this->cacheKey()]);
    }

    public function testANewContainerReadsTheConnectionsAgain(): void
    {
        $params = ['default' => ['driver' => 'pdo_mysql', 'dbname' => 'shop']];

        $this->connections($params, 'build-1')->read(['default']);
        $this->connections($params, 'build-2')->read(['default']);

        self::assertSame(['default' => 2], $this->built);
    }

    public function testForgetReadsTheConnectionsAgain(): void
    {
        $params = ['default' => ['driver' => 'pdo_mysql', 'dbname' => 'shop']];
        $connections = $this->connections($params);

        $connections->read(['default']);
        $connections->forget();
        $connections->read(['default']);

        self::assertSame(['default' => 2], $this->built);
    }

    public function testAConnectionThatCannotBeBuiltIsTriedAgainAfterTheErrorLifetime(): void
    {
        $attempts = 0;
        $locator = new ServiceLocator(['broken' => static function () use (&$attempts): Connection {
            ++$attempts;

            throw new \RuntimeException('Malformed DSN');
        }]);
        $connections = fn(): DoctrineConnections => new DoctrineConnections($locator, ['broken'], $this->cache, $this->clock, 3600, 60);

        self::assertSame(['broken' => 'Malformed DSN'], $connections()->read(['broken']));
        $connections()->read(['broken']);
        self::assertSame(1, $attempts, 'the error is cached');

        $this->clock->sleep(60);
        $connections()->read(['broken']);
        self::assertSame(2, $attempts);
    }

    public function testTheRetriesOfABrokenConnectionDoNotExtendTheOthers(): void
    {
        $locator = new ServiceLocator([
            'default' => function (): Connection {
                $this->built['default'] = ($this->built['default'] ?? 0) + 1;

                return DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => 'shop']);
            },
            'broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN'),
        ]);
        $connections = fn(): DoctrineConnections => new DoctrineConnections($locator, ['default', 'broken'], $this->cache, $this->clock, 180, 60);

        for ($minute = 0; $minute < 4; ++$minute) {
            $connections()->read(['default', 'broken']);
            $this->clock->sleep(60);
        }

        self::assertSame(['default' => 2], $this->built, 'read again once its own lifetime is over');
    }

    public function testTheErrorKeepsItsCause(): void
    {
        $locator = new ServiceLocator(['broken' => static fn(): Connection => throw new \LogicException('Invalid connection configuration', 0, new \RuntimeException('Unknown driver "pdo_foo"'))]);

        self::assertSame(['broken' => 'Invalid connection configuration (Unknown driver "pdo_foo")'], (new DoctrineConnections($locator, ['broken']))->read(['broken']));
    }

    public function testAResetReadsTheCacheAgain(): void
    {
        $params = ['default' => ['driver' => 'pdo_mysql', 'dbname' => 'shop']];
        $connections = $this->connections($params);
        $connections->read(['default']);

        $this->cache->clear();
        $connections->read(['default']);
        self::assertSame(['default' => 1], $this->built, 'kept for the request');

        $connections->reset();
        $connections->read(['default']);
        self::assertSame(['default' => 2], $this->built);
    }

    public function testACachedEntryWithAnUnexpectedShapeIsReadAgain(): void
    {
        $this->cache->save($this->cache->getItem($this->cacheKey())->set(['default' => 'shop', 'reporting' => ['error' => 'Malformed DSN']]));

        $read = $this->connections(['default' => ['driver' => 'pdo_mysql', 'dbname' => 'shop'], 'reporting' => ['driver' => 'pdo_mysql', 'dbname' => 'reports']])->read(['default', 'reporting']);

        self::assertSame(['default' => 1, 'reporting' => 1], $this->built);
        self::assertSame('reports', $read['reporting']['dbname'] ?? null);
    }

    /**
     * @param array<string, array<string, mixed>> $params connection name => parameters
     */
    private function connections(array $params, string $version = 'build'): DoctrineConnections
    {
        $factories = [];

        foreach ($params as $name => $connectionParams) {
            $factories[$name] = function () use ($name, $connectionParams): Connection {
                $this->built[$name] = ($this->built[$name] ?? 0) + 1;

                // @phpstan-ignore argument.type
                return DriverManager::getConnection($connectionParams);
            };
        }

        return new DoctrineConnections(new ServiceLocator($factories), array_keys($params), $this->cache, $this->clock, 3600, 60, $version);
    }

    private function cacheKey(string $version = 'build'): string
    {
        return 'jmonitor_easyadmin.doctrine_connections.' . hash('xxh128', $version);
    }
}
