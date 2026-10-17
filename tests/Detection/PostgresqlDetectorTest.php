<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\Collector\Postgresql\PostgresqlDatabaseCollector;
use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\PostgresqlDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PostgresqlDetectorTest extends TestCase
{
    public function testNoConnectionAtAll(): void
    {
        $detection = (new PostgresqlDetector(new ServiceLocator([]), [], null))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('No Doctrine DBAL connection', (string) $detection->error);
    }

    public function testSqliteIsNotPostgresql(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $detector = new PostgresqlDetector(new ServiceLocator(['default' => static fn(): Connection => $sqlite]), ['default'], null);

        $detection = $detector->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('No PostgreSQL', (string) $detection->error);
    }

    public function testPostgresqlConnectionGivesTheFourCollectors(): void
    {
        $detector = new PostgresqlDetector(new ServiceLocator(['default' => fn(): Connection => $this->postgresqlConnection()]), ['default'], null);

        $detection = $detector->detect();

        self::assertSame(Component::Postgresql, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(['postgresql.activity', 'postgresql.settings', 'postgresql.database', 'postgresql.slow_queries'], $detection->instances[0]->collectors?->names());
        self::assertSame(Lifetime::Dynamic, $detection->instances[0]->collectors->get('postgresql.activity')?->lifetime);
        self::assertTrue($detection->instances[0]->collectors->get('postgresql.activity')->trackDelta);
        self::assertSame(Lifetime::Static, $detection->instances[0]->collectors->get('postgresql.settings')?->lifetime);
        self::assertSame(Lifetime::Minute, $detection->instances[0]->collectors->get('postgresql.database')?->lifetime);
        self::assertTrue($detection->instances[0]->collectors->get('postgresql.database')->trackDelta);
        self::assertSame(Lifetime::Minute, $detection->instances[0]->collectors->get('postgresql.slow_queries')?->lifetime);
        self::assertFalse($detection->instances[0]->collectors->get('postgresql.slow_queries')->trackDelta);
    }

    public function testTheFirstPostgresqlConnectionWinsUnlessOneIsConfigured(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locator = new ServiceLocator([
            'default' => static fn(): Connection => $sqlite,
            'analytics' => fn(): Connection => $this->postgresqlConnection(),
        ]);

        self::assertTrue((new PostgresqlDetector($locator, ['default', 'analytics'], null))->detect()->isFound());
        self::assertTrue((new PostgresqlDetector($locator, ['default', 'analytics'], 'analytics'))->detect()->isFound());

        $forcedSqlite = (new PostgresqlDetector($locator, ['default', 'analytics'], 'default'))->detect();
        self::assertFalse($forcedSqlite->isFound());
        self::assertStringContainsString('not a PostgreSQL connection', (string) $forcedSqlite->error);
    }

    public function testPgsqlAndACustomPostgresqlDriverClassAreDetected(): void
    {
        $pgsql = $this->createMock(Connection::class);
        $pgsql->method('getParams')->willReturn(['driver' => 'pgsql', 'dbname' => 'app']);
        $custom = $this->createMock(Connection::class);
        $custom->method('getParams')->willReturn(['driverClass' => \Doctrine\DBAL\Driver\PDO\PgSQL\Driver::class, 'dbname' => 'app']);

        self::assertTrue((new PostgresqlDetector(new ServiceLocator(['default' => static fn(): Connection => $pgsql]), ['default'], null))->detect()->isFound());
        self::assertTrue((new PostgresqlDetector(new ServiceLocator(['default' => static fn(): Connection => $custom]), ['default'], null))->detect()->isFound());
    }

    public function testTheConfiguredSchemaReachesTheDatabaseCollector(): void
    {
        $detector = new PostgresqlDetector(new ServiceLocator(['default' => fn(): Connection => $this->postgresqlConnection()]), ['default'], null, 'app');

        $collector = $detector->detect()->instances[0]->collectors?->get('postgresql.database')?->collector;

        self::assertInstanceOf(PostgresqlDatabaseCollector::class, $collector);
        self::assertSame('app', (new \ReflectionProperty(PostgresqlDatabaseCollector::class, 'schema'))->getValue($collector));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'no host' => [[], true];
        yield 'localhost' => [['host' => 'localhost'], true];
        yield 'socket directory' => [['host' => '/var/run/postgresql'], true];
        yield 'remote host' => [['host' => 'db.internal'], false];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('hosts')]
    public function testTheHostRamIsCollectedOnlyForALocalServer(array $params, bool $local): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_pgsql', 'dbname' => 'app'] + $params);
        $system = $this->createMock(AdapterInterface::class);

        $detection = (new PostgresqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], null, 'public', 'Linux', $system))->detect();

        self::assertSame($local, \in_array('system.ram_total', $detection->instances[0]->collectors?->names() ?? [], true));
        if ($local) {
            self::assertSame(Lifetime::Static, $detection->instances[0]->collectors?->get('system.ram_total')?->lifetime);
        }
    }

    public function testNoHostRamOutsideLinux(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_pgsql', 'host' => 'localhost', 'dbname' => 'app']);
        $detector = new PostgresqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], null, 'public', 'Windows');

        self::assertNotContains('system.ram_total', $detector->detect()->instances[0]->collectors?->names() ?? []);
    }

    public function testUnknownConfiguredConnection(): void
    {
        $detection = (new PostgresqlDetector(new ServiceLocator([]), ['default'], 'missing'))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('Unknown Doctrine connection "missing"', (string) $detection->error);
    }

    public function testDetectionReadsTheParametersAndNeverTouchesTheServer(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_pgsql', 'host' => 'db.internal', 'dbname' => 'app']);
        $connection->expects(self::never())->method('getDatabasePlatform');
        $connection->expects(self::never())->method('getDatabase');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $detection = (new PostgresqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], null))->detect();

        self::assertTrue($detection->isFound());
    }

    private function postgresqlConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_pgsql', 'host' => 'db.internal', 'dbname' => 'app']);

        return $connection;
    }
}
