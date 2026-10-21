<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\LoggingShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Logging\RecordingLogger;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LoggingShellExecutorTest extends TestCase
{
    public function testReturnsTheOutputWithoutLogging(): void
    {
        $logger = new RecordingLogger();

        self::assertSame("out\n", (new LoggingShellExecutor($logger, new FixtureShellExecutor("out\n")))->execute('nginx -T'));
        self::assertSame([], $logger->records);
    }

    public function testANullOutputIsLogged(): void
    {
        $logger = new RecordingLogger();

        self::assertNull((new LoggingShellExecutor($logger, new FixtureShellExecutor(null)))->execute('nginx -T'));
        self::assertSame([[LogLevel::WARNING, '"{command}" gave no output.', ['command' => 'nginx -T']]], $logger->records);
    }

    public function testAFailureIsANullOutputAndIsLogged(): void
    {
        $logger = new RecordingLogger();
        $failing = new class extends ShellExecutor {
            public function execute(string $command): ?string
            {
                throw new \RuntimeException('exit code 1 (General error): open() "/run/nginx.pid" failed (13: Permission denied)');
            }
        };

        self::assertNull((new LoggingShellExecutor($logger, $failing))->execute('nginx -T'));
        self::assertSame('Cannot run "{command}": {reason}', $logger->records[0][1] ?? null);
        self::assertSame('exit code 1 (General error): open() "/run/nginx.pid" failed (13: Permission denied)', $logger->records[0][2]['reason'] ?? null);
    }
}
