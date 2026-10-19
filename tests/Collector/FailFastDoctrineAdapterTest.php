<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Jmonitor\EasyAdminBundle\Collector\FailFastDoctrineAdapter;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use PHPUnit\Framework\TestCase;

final class FailFastDoctrineAdapterTest extends TestCase
{
    public function testReturnsTheRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->with('SELECT 1 AS one', ['a' => 1])->willReturn([['one' => 1]]);

        self::assertSame([['one' => 1]], (new FailFastDoctrineAdapter($connection))->fetchAllAssociative('SELECT 1 AS one', ['a' => 1]));
    }

    public function testAServerThatCannotBeReachedIsTriedOnce(): void
    {
        $refused = new \RuntimeException('SQLSTATE[HY000] [2002] Connection timed out');
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->expects(self::once())->method('fetchAllAssociative')->willThrowException($refused);
        $adapter = new FailFastDoctrineAdapter($connection);

        $first = self::failure(static fn() => $adapter->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));
        $second = self::failure(static fn() => $adapter->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'"));

        self::assertInstanceOf(UnreachableServerException::class, $first);
        self::assertSame('SQLSTATE[HY000] [2002] Connection timed out', $first->getMessage());
        self::assertSame($refused, $first->getPrevious());
        self::assertSame($first, $second);
    }

    public function testAQueryFailingOnAnOpenConnectionLeavesTheNextOnesAlone(): void
    {
        $denied = new \RuntimeException('SELECT command denied to user');
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $query): array => str_contains($query, 'performance_schema') ? throw $denied : [['Value' => '8.4']]);
        $adapter = new FailFastDoctrineAdapter($connection);

        self::assertSame($denied, self::failure(static fn() => $adapter->fetchAllAssociative('SELECT 1 FROM performance_schema.events_statements_summary_by_digest')));
        self::assertSame([['Value' => '8.4']], $adapter->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'"));
    }

    public function testAnOpenConnectionDroppedByTheServerIsReopenedOnce(): void
    {
        $calls = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$calls): array {
            return ++$calls === 1 ? throw self::connectionLost() : [['Value' => '8.4']];
        });

        self::assertSame([['Value' => '8.4']], (new FailFastDoctrineAdapter($connection))->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'"));
        self::assertSame(2, $calls);
    }

    public function testAConnectionLostWhileOpeningItIsNotTriedAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->expects(self::once())->method('fetchAllAssociative')->willThrowException(self::connectionLost());

        $failure = self::failure(static fn() => (new FailFastDoctrineAdapter($connection))->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertInstanceOf(ConnectionLost::class, $failure->getPrevious());
    }

    private static function connectionLost(): ConnectionLost
    {
        return new ConnectionLost(new class ('server closed the connection unexpectedly') extends AbstractException {}, null);
    }

    private static function failure(\Closure $query): \Throwable
    {
        try {
            $query();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('The query should have failed.');
    }
}
