<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Logging;

use Jmonitor\EasyAdminBundle\Logging\MinimumLevelLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class MinimumLevelLoggerTest extends TestCase
{
    public function testForwardsWarningsAndAboveOnly(): void
    {
        $records = [];
        $inner = new class ($records) extends AbstractLogger {
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $logger = new MinimumLevelLogger($inner);
        $logger->notice('No endpoint URL provided');
        $logger->info('Metrics collected');
        $logger->warning('messenger:stats failed', ['exit_code' => 1]);
        $logger->error('Failed to boot collector');
        $logger->log(LogLevel::CRITICAL, 'boom');

        self::assertSame([
            [LogLevel::WARNING, 'messenger:stats failed', ['exit_code' => 1]],
            [LogLevel::ERROR, 'Failed to boot collector', []],
            [LogLevel::CRITICAL, 'boom', []],
        ], $records);
    }
}
