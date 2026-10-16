<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector\Symfony;

use Jmonitor\EasyAdminBundle\Collector\Symfony\ComponentCollectorInterface;
use Jmonitor\EasyAdminBundle\Collector\Symfony\SymfonyCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\KernelInterface;

final class SymfonyCollectorTest extends TestCase
{
    public function testItDescribesTheKernelAndItsComponents(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');
        $kernel->method('isDebug')->willReturn(false);
        $kernel->method('getBundles')->willReturn(['FrameworkBundle' => [], 'EasyAdminBundle' => []]);
        $kernel->method('getProjectDir')->willReturn('/project');
        $kernel->method('getCharset')->willReturn('UTF-8');

        $result = (new SymfonyCollector($kernel, [
            'filled' => $this->component(['foo' => 'bar']),
            'empty' => $this->component([]),
        ]))->collect();

        self::assertSame('prod', $result['env']);
        self::assertFalse($result['debug']);
        self::assertSame(['FrameworkBundle', 'EasyAdminBundle'], $result['bundles']);
        self::assertSame('/project', $result['project_dir']);
        self::assertSame('UTF-8', $result['charset']);
        self::assertSame(['filled' => ['foo' => 'bar']], $result['components']);
        self::assertSame([], $result['errors']);
    }

    public function testAComponentThatFailsToBootIsDisabledAlone(): void
    {
        $broken = $this->createMock(ComponentCollectorInterface::class);
        $broken->method('boot')->willThrowException(new BootFailedException('wrapped', new CollectorException('unable to run', 'broken')));
        $broken->expects(self::never())->method('collect');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(LogLevel::WARNING, self::anything(), self::callback(
                static fn(array $context): bool => $context['component'] === 'broken' && $context['exception'] instanceof CollectorException,
            ));

        $collector = new SymfonyCollector($this->createStub(KernelInterface::class), [
            'broken' => $broken,
            'healthy' => $this->component(['foo' => 'bar']),
        ]);
        $collector->setLogger($logger);
        $collector->boot();

        $result = $collector->collect();

        self::assertSame(['healthy' => ['foo' => 'bar']], $result['components']);
        self::assertSame(['broken' => 'Collector broken failed: unable to run'], $result['errors'], 'the cause, not the wrapping exception');
    }

    public function testAnUnexpectedBootFailureIsAnError(): void
    {
        $broken = $this->createStub(ComponentCollectorInterface::class);
        $broken->method('boot')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(LogLevel::ERROR, self::anything(), self::callback(static fn(array $context): bool => $context['message'] === 'boom'));

        $collector = new SymfonyCollector($this->createStub(KernelInterface::class), ['broken' => $broken]);
        $collector->setLogger($logger);
        $collector->boot();

        $result = $collector->collect();

        self::assertSame([], $result['components']);
        self::assertSame(['broken' => 'boom'], $result['errors']);
    }

    public function testAComponentThatFailsToCollectDoesNotBreakTheOthers(): void
    {
        $broken = $this->createStub(ComponentCollectorInterface::class);
        $broken->method('collect')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::anything(), self::callback(static fn(array $context): bool => $context['message'] === 'boom'));

        $collector = new SymfonyCollector($this->createStub(KernelInterface::class), [
            'broken' => $broken,
            'healthy' => $this->component(['foo' => 'bar']),
        ]);
        $collector->setLogger($logger);

        $result = $collector->collect();

        self::assertSame(['healthy' => ['foo' => 'bar']], $result['components']);
        self::assertSame(['broken' => 'boom'], $result['errors']);
    }

    public function testACollectFailureIsReported(): void
    {
        $broken = $this->createStub(ComponentCollectorInterface::class);
        $broken->method('collect')->willThrowException(new CollectorException('messenger:stats command failed', 'broken'));

        $result = (new SymfonyCollector($this->createStub(KernelInterface::class), ['broken' => $broken]))->collect();

        self::assertSame(['broken' => 'Collector broken failed: messenger:stats command failed'], $result['errors']);
    }

    /**
     * @param array<mixed> $payload
     */
    private function component(array $payload): ComponentCollectorInterface
    {
        $component = $this->createStub(ComponentCollectorInterface::class);
        $component->method('collect')->willReturn($payload);

        return $component;
    }
}
