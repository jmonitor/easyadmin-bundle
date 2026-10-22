<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\EasyAdminBundle\Detection\DoctrineConnections;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Detection\PostgresqlDetector;
use Jmonitor\EasyAdminBundle\Detection\SkippedConnectionReporter;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Logging\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SkippedConnectionReporterTest extends TestCase
{
    public function testWarnsOnceForTheSameConnectionAndError(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600);

        $reporter->report('broken', 'Malformed DSN');
        $reporter->report('broken', 'Malformed DSN');

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertSame('Doctrine connection "{connection}" could not be read, the monitoring skips it: {error}', $message);
        self::assertSame('broken', $context['connection']);
        self::assertSame('Malformed DSN', $context['error']);
    }

    public function testWarnsAgainForAnotherConnectionOrAnotherError(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600);

        $reporter->report('broken', 'Malformed DSN');
        $reporter->report('broken', 'Unknown driver');
        $reporter->report('other/{weird}', 'Malformed DSN');

        self::assertSame(['broken', 'broken', 'other/{weird}'], array_map(static fn(array $record): string => $record[2]['connection'], $logger->records));
    }

    public function testTheWarningComesBackOnceItsLifetimeIsOver(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 0);

        $reporter->report('broken', 'Malformed DSN');
        $reporter->report('broken', 'Malformed DSN');

        self::assertCount(2, $logger->records);
    }

    public function testBothDatabaseDetectorsShareOneWarning(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600);
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locator = new ServiceLocator([
            'broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN'),
            'default' => static fn(): Connection => $sqlite,
        ]);

        (new MysqlDetector(new DoctrineConnections($locator, ['broken', 'default']), [], skipped: $reporter))->detect();
        (new PostgresqlDetector(new DoctrineConnections($locator, ['broken', 'default']), [], skipped: $reporter))->detect();
        (new MysqlDetector(new DoctrineConnections($locator, ['broken', 'default']), [], skipped: $reporter))->detect();

        self::assertCount(1, $logger->records);
    }

    public function testAConnectionShownByAnotherComponentIsNotLogged(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600, ['broken']);
        $locator = new ServiceLocator(['broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN')]);

        $mysql = (new MysqlDetector(new DoctrineConnections($locator, ['broken']), ['broken' => []], skipped: $reporter))->detect();
        (new PostgresqlDetector(new DoctrineConnections($locator, ['broken']), [], skipped: $reporter))->detect();

        self::assertSame('Malformed DSN', $mysql->instances[0]->error, 'the MySQL tab shows it');
        self::assertSame([], $logger->records);
    }

    public function testAFailingCacheOrLoggerNeverBreaksTheDetection(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Cache down'));
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('Log file not writable');
            }
        };
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locator = new ServiceLocator([
            'broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN'),
            'default' => static fn(): Connection => $sqlite,
        ]);

        foreach ([new SkippedConnectionReporter($cache, new RecordingLogger(), 3600), new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600)] as $reporter) {
            $reporter->report('broken', 'Malformed DSN');

            $detection = (new PostgresqlDetector(new DoctrineConnections($locator, ['broken', 'default']), [], skipped: $reporter))->detect();
            self::assertFalse($detection->isFound(), 'SQLite is not PostgreSQL: the detection went through');
            self::assertStringContainsString('Connection "broken" could not be read: Malformed DSN', (string) $detection->error);
        }
    }

    public function testTheSkippedConnectionsOfTheRequestAreKept(): void
    {
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), new RecordingLogger(), 3600);

        $reporter->report('legacy', 'refused');
        $reporter->report('old', 'gone');
        $reporter->report('legacy', 'refused');

        self::assertSame(['legacy' => 'refused', 'old' => 'gone'], $reporter->skipped());
    }

    public function testAShownConnectionIsNotKept(): void
    {
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), new RecordingLogger(), 3600, ['main']);

        $reporter->report('main', 'refused');

        self::assertSame([], $reporter->skipped());
    }

    public function testAConnectionIsKeptEvenWhenAlreadyWarned(): void
    {
        $cache = new ArrayAdapter();
        (new SkippedConnectionReporter($cache, new RecordingLogger(), 3600))->report('legacy', 'refused');
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter($cache, $logger, 3600);

        $reporter->report('legacy', 'refused');

        self::assertSame([], $logger->records);
        self::assertSame(['legacy' => 'refused'], $reporter->skipped());
    }

    public function testAConnectionIsKeptEvenWhenTheCacheFails(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Cache down'));
        $reporter = new SkippedConnectionReporter($cache, new RecordingLogger(), 3600);

        $reporter->report('legacy', 'refused');

        self::assertSame(['legacy' => 'refused'], $reporter->skipped());
    }

    public function testResetForgetsTheSkippedConnections(): void
    {
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), new RecordingLogger(), 3600);
        $reporter->report('legacy', 'refused');

        $reporter->reset();

        self::assertSame([], $reporter->skipped());
    }

    public function testAConfiguredConnectionInErrorIsShownNotLogged(): void
    {
        $logger = new RecordingLogger();
        $locator = new ServiceLocator(['broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN')]);

        $detection = (new MysqlDetector(new DoctrineConnections($locator, ['broken']), ['broken' => []], skipped: new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600)))->detect();

        self::assertSame('Malformed DSN', $detection->instances[0]->error);
        self::assertSame([], $logger->records);
    }
}
