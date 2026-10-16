<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector\Symfony;

use Jmonitor\EasyAdminBundle\Collector\Symfony\CommandRunner;
use Jmonitor\EasyAdminBundle\Collector\Symfony\MessengerStatsCollector;
use Jmonitor\Exceptions\CollectorException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class MessengerStatsCollectorTest extends TestCase
{
    public function testTheBootSampleIsServedByTheNextCollect(): void
    {
        $stats = ['transports' => ['async' => ['count' => 42], 'failed' => ['count' => 0]]];

        $runner = $this->createMock(CommandRunner::class);
        $runner->expects(self::exactly(2))
            ->method('runPhpProcess')
            ->with(['bin/console', 'messenger:stats', '--format=json'], 3)
            ->willReturn(['exit_code' => 0, 'output' => json_encode($stats, JSON_THROW_ON_ERROR), 'error_output' => '']);

        $collector = new MessengerStatsCollector($runner);
        $collector->boot();

        self::assertSame($stats, $collector->collect());
        self::assertSame($stats, $collector->collect());
    }

    public function testAFailedCommandFails(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runPhpProcess')->willReturn(['exit_code' => 1, 'output' => '', 'error_output' => "\n  The \"--format\" option does not exist.  \n"]);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('messenger:stats command failed (exit code: 1): The "--format" option does not exist.');

        (new MessengerStatsCollector($runner))->collect();
    }

    public function testAFailedCommandWithoutErrorOutputReportsItsOutput(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runPhpProcess')->willReturn(['exit_code' => 255, 'output' => 'PHP Fatal error: Allowed memory size exhausted', 'error_output' => '']);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('messenger:stats command failed (exit code: 255): PHP Fatal error: Allowed memory size exhausted');

        (new MessengerStatsCollector($runner))->collect();
    }

    public function testAProcessThatTimesOutFails(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runPhpProcess')->willThrowException(new ProcessTimedOutException(new Process(['php']), ProcessTimedOutException::TYPE_GENERAL));

        $this->expectException(CollectorException::class);

        (new MessengerStatsCollector($runner))->collect();
    }

    public function testAnOutputThatIsNotJsonFails(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runPhpProcess')->willReturn(['exit_code' => 0, 'output' => 'not-json', 'error_output' => '']);

        $this->expectException(CollectorException::class);

        (new MessengerStatsCollector($runner))->collect();
    }
}
