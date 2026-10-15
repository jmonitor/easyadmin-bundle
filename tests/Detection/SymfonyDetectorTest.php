<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\Symfony\SymfonyCollector;
use Jmonitor\EasyAdminBundle\Detection\SymfonyDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

final class SymfonyDetectorTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    public function testTheKernelIsEnoughToDescribeSymfony(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $detection = (new SymfonyDetector($kernel, messenger: false, scheduler: false, flex: false))->detect();

        self::assertTrue($detection->isFound());
        $spec = $detection->collectors?->get('symfony');
        self::assertNotNull($spec);
        self::assertInstanceOf(SymfonyCollector::class, $spec->collector);
        self::assertSame(Lifetime::Static, $spec->lifetime);

        $data = (new CollectorRunner())->run($spec->collector);

        self::assertSame('test', $data['env']);
        self::assertTrue($data['debug']);
        self::assertContains('JmonitorEasyAdminBundle', $data['bundles']);
        self::assertSame([], $data['components'], 'no sub-collector was enabled');

        $kernel->shutdown();
    }

    public function testSubCollectorsAreOnlyBuiltWhenEnabled(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $detection = (new SymfonyDetector($kernel, messenger: true, scheduler: true, flex: true))->detect();

        self::assertTrue($detection->isFound());
        self::assertInstanceOf(SymfonyCollector::class, $detection->collectors?->get('symfony')?->collector);

        $kernel->shutdown();
    }
}
