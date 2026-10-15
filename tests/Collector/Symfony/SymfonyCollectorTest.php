<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector\Symfony;

use Jmonitor\EasyAdminBundle\Collector\Symfony\ComponentCollectorInterface;
use Jmonitor\EasyAdminBundle\Collector\Symfony\SymfonyCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
    }

    public function testAComponentThatFailsToBootIsDisabledAlone(): void
    {
        $broken = $this->createMock(ComponentCollectorInterface::class);
        $broken->method('boot')->willThrowException(new BootFailedException('wrapped', new CollectorException('unable to run', 'broken')));
        $broken->expects(self::never())->method('collect');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::anything(), self::callback(
                static fn(array $context): bool => $context['component'] === 'broken' && $context['exception'] instanceof CollectorException,
            ));

        $collector = new SymfonyCollector($this->createStub(KernelInterface::class), [
            'broken' => $broken,
            'healthy' => $this->component(['foo' => 'bar']),
        ]);
        $collector->setLogger($logger);
        $collector->boot();

        self::assertSame(['healthy' => ['foo' => 'bar']], $collector->collect()['components']);
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

        self::assertSame(['healthy' => ['foo' => 'bar']], $collector->collect()['components']);
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
