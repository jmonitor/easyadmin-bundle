<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MysqlDetectorTest extends TestCase
{
    public function testNoConnectionAtAll(): void
    {
        $detection = (new MysqlDetector(new ServiceLocator([]), [], []))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('No Doctrine DBAL connection', (string) $detection->error);
    }

    public function testSqliteIsNotMysql(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $detector = new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $sqlite]), ['default'], []);

        $detection = $detector->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('No MySQL or MariaDB', (string) $detection->error);
    }

    public function testMysqlConnectionGivesTheFourCollectors(): void
    {
        $detector = new MysqlDetector(new ServiceLocator(['default' => fn(): Connection => $this->mysqlConnection('app')]), ['default'], []);

        $detection = $detector->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(['mysql.status', 'mysql.variables', 'mysql.information_schema', 'mysql.slow_queries'], $detection->instances[0]->collectors?->names());
        self::assertTrue($detection->instances[0]->collectors->get('mysql.status')?->trackDelta);
        self::assertSame(Lifetime::Dynamic, $detection->instances[0]->collectors->get('mysql.status')->lifetime);
        self::assertSame(Lifetime::Static, $detection->instances[0]->collectors->get('mysql.variables')?->lifetime);
        self::assertSame(Lifetime::Static, $detection->instances[0]->collectors->get('mysql.information_schema')?->lifetime);
        self::assertSame(Lifetime::Minute, $detection->instances[0]->collectors->get('mysql.slow_queries')?->lifetime);
    }

    public function testEveryMysqlConnectionGivesAnInstanceInDoctrineOrder(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locator = new ServiceLocator([
            'default' => fn(): Connection => $this->mysqlConnection('shop'),
            'cache' => static fn(): Connection => $sqlite,
            'reporting' => fn(): Connection => $this->mysqlConnection('reports'),
        ]);

        $detection = (new MysqlDetector($locator, ['default', 'cache', 'reporting'], []))->detect();

        self::assertSame(['default', 'reporting'], self::ids($detection->instances));
        self::assertSame(['shop', 'reports'], array_map(static fn(Instance $instance): ?string => $instance->detail, $detection->instances));
        self::assertTrue($detection->instances[1]->isFound());
        self::assertSame(Component::Mysql, $detection->instances[1]->component);
    }

    public function testConfiguredConnectionsAreShownEvenWhenUnusable(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locator = new ServiceLocator([
            'default' => static fn(): Connection => $sqlite,
            'reporting' => fn(): Connection => $this->mysqlConnection('reports'),
        ]);

        $detection = (new MysqlDetector($locator, ['default', 'reporting'], ['reporting' => [], 'default' => [], 'missing' => []]))->detect();

        self::assertSame(['reporting', 'default', 'missing'], self::ids($detection->instances));
        self::assertTrue($detection->instances[0]->isFound());
        self::assertSame('Doctrine connection "default" is not a MySQL or MariaDB connection.', $detection->instances[1]->error);
        self::assertSame('Unknown Doctrine connection "missing".', $detection->instances[2]->error);
    }

    public function testAConnectionThatCannotBeBuiltIsSkippedAndReported(): void
    {
        $locator = new ServiceLocator([
            'broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN'),
            'default' => fn(): Connection => $this->mysqlConnection('shop'),
        ]);

        $auto = (new MysqlDetector($locator, ['broken', 'default'], []))->detect();
        self::assertSame(['default'], self::ids($auto->instances));

        $onlyBroken = (new MysqlDetector(new ServiceLocator(['broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN')]), ['broken'], []))->detect();
        self::assertFalse($onlyBroken->isFound());
        self::assertSame('No MySQL or MariaDB Doctrine connection found. Connection "broken" could not be read: Malformed DSN', $onlyBroken->error);

        $named = (new MysqlDetector($locator, ['broken', 'default'], ['broken' => []]))->detect();
        self::assertSame('Malformed DSN', $named->instances[0]->error);
        self::assertSame('broken', $named->instances[0]->id);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'no host' => [[], true];
        yield 'localhost' => [['host' => 'localhost'], true];
        yield 'loopback' => [['host' => '127.0.0.1'], true];
        yield 'IPv6 loopback' => [['host' => '::1'], true];
        yield 'socket' => [['host' => 'db.internal', 'unix_socket' => '/run/mysqld/mysqld.sock'], true];
        yield 'remote host' => [['host' => 'db.internal'], false];
        yield 'compose service' => [['host' => 'database'], false];
        yield 'remote primary' => [['primary' => ['host' => 'db.internal']], false];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('hosts')]
    public function testTheHostRamIsCollectedOnlyForALocalServer(array $params, bool $local): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'app'] + $params);
        $system = $this->createMock(AdapterInterface::class);

        $detection = (new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], [], 'Linux', $system))->detect();

        self::assertSame($local, \in_array('system.ram_total', $detection->instances[0]->collectors?->names() ?? [], true));
        if ($local) {
            self::assertSame(Lifetime::Static, $detection->instances[0]->collectors?->get('system.ram_total')?->lifetime);
        }
    }

    public function testNoHostRamOutsideLinux(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => 'localhost', 'dbname' => 'app']);
        $detector = new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], [], 'Windows');

        self::assertNotContains('system.ram_total', $detector->detect()->instances[0]->collectors?->names() ?? []);
    }

    public function testANumericConnectionNameIsAStringId(): void
    {
        $locator = new ServiceLocator(['2024' => fn(): Connection => $this->mysqlConnection('archive')]);

        $auto = (new MysqlDetector($locator, ['2024'], []))->detect();
        $configured = (new MysqlDetector($locator, ['2024'], ['2024' => []]))->detect();

        self::assertSame(['2024'], self::ids($auto->instances));
        self::assertSame(['2024'], self::ids($configured->instances));
        self::assertTrue($configured->instances[0]->isFound());
    }

    public function testAConfiguredConnectionWithoutDoctrineExplainsThatDoctrineIsMissing(): void
    {
        $detection = (new MysqlDetector(new ServiceLocator([]), [], ['default' => []]))->detect();

        self::assertSame(['default'], self::ids($detection->instances));
        self::assertSame('No Doctrine DBAL connection is configured.', $detection->instances[0]->error);
    }

    public function testUnknownConfiguredConnection(): void
    {
        $detection = (new MysqlDetector(new ServiceLocator([]), ['default'], ['missing' => []]))->detect();

        self::assertTrue($detection->isFound(), 'a configured connection is always listed');
        self::assertFalse($detection->instances[0]->isFound());
        self::assertSame('Unknown Doctrine connection "missing".', $detection->instances[0]->error);
    }

    public function testDetectionReadsTheParametersAndNeverTouchesTheServer(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => 'db.internal', 'dbname' => 'app']);
        $connection->expects(self::never())->method('getDatabasePlatform');
        $connection->expects(self::never())->method('getDatabase');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $detection = (new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], []))->detect();

        self::assertTrue($detection->isFound());
    }

    public function testMysqliAndACustomMysqlDriverClassAreDetected(): void
    {
        $mysqli = $this->createMock(Connection::class);
        $mysqli->method('getParams')->willReturn(['driver' => 'mysqli', 'dbname' => 'app']);
        $custom = $this->createMock(Connection::class);
        $custom->method('getParams')->willReturn(['driverClass' => \Doctrine\DBAL\Driver\PDO\MySQL\Driver::class, 'dbname' => 'app']);

        self::assertTrue((new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $mysqli]), ['default'], []))->detect()->isFound());
        self::assertTrue((new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $custom]), ['default'], []))->detect()->isFound());
    }

    public function testAnUnreachableServerIsDetectedAndFailsAtCollectTime(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'app']);
        $connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
        $detection = (new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], []))->detect();

        self::assertTrue($detection->isFound(), 'the tab stays, the outage is shown inside it');

        try {
            (new CollectorRunner())->run($detection->instances[0]->collectors->get('mysql.status')->collector);
            self::fail('Collecting from a dead server must fail');
        } catch (\Throwable $e) {
            self::assertStringContainsString('Connection refused', $e->getPrevious()?->getMessage() ?? $e->getMessage());
        }
    }

    /**
     * @param list<Instance> $instances
     *
     * @return list<?string>
     */
    private static function ids(array $instances): array
    {
        return array_map(static fn(Instance $instance): ?string => $instance->id, $instances);
    }

    private function mysqlConnection(string $database): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => 'db.internal', 'dbname' => $database]);

        return $connection;
    }
}
