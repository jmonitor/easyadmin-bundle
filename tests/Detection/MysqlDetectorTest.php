<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MysqlDetectorTest extends TestCase
{
    public function testNoConnectionAtAll(): void
    {
        $detection = (new MysqlDetector(new ServiceLocator([]), [], null))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('No Doctrine DBAL connection', (string) $detection->error);
    }

    public function testSqliteIsNotMysql(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $detector = new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $sqlite]), ['default'], null);

        $detection = $detector->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('No MySQL or MariaDB', (string) $detection->error);
    }

    public function testMysqlConnectionGivesTheFourCollectors(): void
    {
        $detector = new MysqlDetector(new ServiceLocator(['default' => fn(): Connection => $this->mysqlConnection('app')]), ['default'], null);

        $detection = $detector->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(['mysql.status', 'mysql.variables', 'mysql.information_schema', 'mysql.slow_queries'], $detection->collectors?->names());
        self::assertTrue($detection->collectors->get('mysql.status')?->trackDelta);
        self::assertSame(Lifetime::Dynamic, $detection->collectors->get('mysql.status')->lifetime);
        self::assertSame(Lifetime::Static, $detection->collectors->get('mysql.variables')?->lifetime);
        self::assertSame(Lifetime::Static, $detection->collectors->get('mysql.information_schema')?->lifetime);
        self::assertSame(Lifetime::Minute, $detection->collectors->get('mysql.slow_queries')?->lifetime);
    }

    public function testTheFirstMysqlConnectionWinsUnlessOneIsConfigured(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locator = new ServiceLocator([
            'default' => static fn(): Connection => $sqlite,
            'reporting' => fn(): Connection => $this->mysqlConnection('reports'),
        ]);

        self::assertTrue((new MysqlDetector($locator, ['default', 'reporting'], null))->detect()->isFound());
        self::assertTrue((new MysqlDetector($locator, ['default', 'reporting'], 'reporting'))->detect()->isFound());

        $forcedSqlite = (new MysqlDetector($locator, ['default', 'reporting'], 'default'))->detect();
        self::assertFalse($forcedSqlite->isFound());
        self::assertStringContainsString('not a MySQL or MariaDB connection', (string) $forcedSqlite->error);
    }

    public function testUnknownConfiguredConnection(): void
    {
        $detection = (new MysqlDetector(new ServiceLocator([]), ['default'], 'missing'))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('Unknown Doctrine connection "missing"', (string) $detection->error);
    }

    public function testUnreachableServerIsReportedNotThrown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
        $detector = new MysqlDetector(new ServiceLocator(['default' => static fn(): Connection => $connection]), ['default'], null);

        $detection = $detector->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('Connection refused', (string) $detection->error);
        self::assertStringContainsString('"default"', (string) $detection->error);
    }

    private function mysqlConnection(string $database): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('getDatabase')->willReturn($database);

        return $connection;
    }
}
