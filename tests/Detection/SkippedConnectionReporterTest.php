<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Detection\PostgresqlDetector;
use Jmonitor\EasyAdminBundle\Detection\SkippedConnectionReporter;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Logging\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SkippedConnectionReporterTest extends TestCase
{
    public function testWarnsOnceForTheSameConnectionAndError(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600);

        $reporter->report('broken', new \RuntimeException('Malformed DSN'));
        $reporter->report('broken', new \RuntimeException('Malformed DSN'));

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertSame('Doctrine connection "{connection}" could not be read, the monitoring skips it: {error}', $message);
        self::assertSame('broken', $context['connection']);
        self::assertSame('Malformed DSN', $context['error']);
        self::assertInstanceOf(\RuntimeException::class, $context['exception']);
    }

    public function testWarnsAgainForAnotherConnectionOrAnotherError(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600);

        $reporter->report('broken', new \RuntimeException('Malformed DSN'));
        $reporter->report('broken', new \RuntimeException('Unknown driver'));
        $reporter->report('other/{weird}', new \RuntimeException('Malformed DSN'));

        self::assertSame(['broken', 'broken', 'other/{weird}'], array_map(static fn(array $record): string => $record[2]['connection'], $logger->records));
    }

    public function testTheWarningComesBackOnceItsLifetimeIsOver(): void
    {
        $logger = new RecordingLogger();
        $reporter = new SkippedConnectionReporter(new ArrayAdapter(), $logger, 0);

        $reporter->report('broken', new \RuntimeException('Malformed DSN'));
        $reporter->report('broken', new \RuntimeException('Malformed DSN'));

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

        (new MysqlDetector($locator, ['broken', 'default'], [], skipped: $reporter))->detect();
        (new PostgresqlDetector($locator, ['broken', 'default'], [], skipped: $reporter))->detect();
        (new MysqlDetector($locator, ['broken', 'default'], [], skipped: $reporter))->detect();

        self::assertCount(1, $logger->records);
    }

    public function testAConfiguredConnectionInErrorIsShownNotLogged(): void
    {
        $logger = new RecordingLogger();
        $locator = new ServiceLocator(['broken' => static fn(): Connection => throw new \RuntimeException('Malformed DSN')]);

        $detection = (new MysqlDetector($locator, ['broken'], ['broken' => []], skipped: new SkippedConnectionReporter(new ArrayAdapter(), $logger, 3600)))->detect();

        self::assertSame('Malformed DSN', $detection->instances[0]->error);
        self::assertSame([], $logger->records);
    }
}
