<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
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

    public function testReleaseClosesTheConnectionTheCollectOpened(): void
    {
        $connected = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturnCallback(static function () use (&$connected): bool {
            return $connected;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$connected): array {
            $connected = true;

            return [['Value' => '8.4']];
        });
        $connection->expects(self::once())->method('close');
        $adapter = new FailFastDoctrineAdapter($connection);

        $adapter->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'");
        $adapter->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'");
        $adapter->release();
        $adapter->release();
    }

    public function testReleaseClosesAConnectionOpenedByAFailingQuery(): void
    {
        $connected = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturnCallback(static function () use (&$connected): bool {
            return $connected;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$connected): array {
            $connected = true;

            throw new \RuntimeException('SELECT command denied to user');
        });
        $connection->expects(self::once())->method('close');
        $adapter = new FailFastDoctrineAdapter($connection);

        self::failure(static fn() => $adapter->fetchAllAssociative('SELECT 1 FROM performance_schema.events_statements_summary_by_digest'));
        $adapter->release();
    }

    public function testReleaseLeavesAloneAConnectionThatWasAlreadyOpenOrNeverOpened(): void
    {
        $open = $this->createMock(Connection::class);
        $open->method('isConnected')->willReturn(true);
        $open->method('fetchAllAssociative')->willReturn([['Value' => '8.4']]);
        $open->expects(self::never())->method('close');
        $unreachable = $this->createMock(Connection::class);
        $unreachable->method('isConnected')->willReturn(false);
        $unreachable->method('fetchAllAssociative')->willThrowException(new \RuntimeException('Connection timed out'));
        $unreachable->expects(self::never())->method('close');
        $openAdapter = new FailFastDoctrineAdapter($open);
        $unreachableAdapter = new FailFastDoctrineAdapter($unreachable);

        $openAdapter->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'");
        self::failure(static fn() => $unreachableAdapter->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));
        $openAdapter->release();
        $unreachableAdapter->release();
    }

    public function testAConnectionWithReplicasIsQueriedOnItsPrimary(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->method('isConnectedToPrimary')->willReturn(false);
        $connection->expects(self::once())->method('ensureConnectedToPrimary');
        $connection->method('fetchAllAssociative')->willReturn([['Value' => '8.4']]);

        self::assertSame([['Value' => '8.4']], (new FailFastDoctrineAdapter($connection))->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'"));
    }

    public function testAConnectionAlreadyOnItsPrimaryStaysThere(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->method('isConnectedToPrimary')->willReturn(true);
        $connection->expects(self::never())->method('ensureConnectedToPrimary');
        $connection->method('fetchAllAssociative')->willReturn([['Value' => '8.4']]);

        self::assertSame([['Value' => '8.4']], (new FailFastDoctrineAdapter($connection))->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'"));
    }

    public function testAnUnreachablePrimaryIsUnreachableEvenWithAnOpenReplica(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isConnectedToPrimary')->willReturn(false);
        $connection->expects(self::once())->method('ensureConnectedToPrimary')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection timed out'));
        $adapter = new FailFastDoctrineAdapter($connection);

        $first = self::failure(static fn() => $adapter->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));

        self::assertInstanceOf(UnreachableServerException::class, $first);
        self::assertSame($first, self::failure(static fn() => $adapter->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'")));
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
