<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

final class BundleBootTest extends TestCase
{
    /**
     * FrameworkBundle registers an exception handler at boot and never removes it,
     * which PHPUnit reports as a risky test.
     */
    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    public function testTheBundleBootsWithoutConfiguration(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        $config = $kernel->getContainer()->getParameter('jmonitor_easyadmin.config');

        self::assertSame('cache.app', $config['cache_pool']);
        self::assertSame('auto', $config['components']['mysql']['enabled']);

        $kernel->shutdown();
    }

    public function testTheBundleBootsWithConfiguration(): void
    {
        $kernel = new TestKernel(['polling_interval' => 0, 'components' => ['redis' => false]]);
        $kernel->boot();

        $config = $kernel->getContainer()->getParameter('jmonitor_easyadmin.config');

        self::assertSame(0, $config['polling_interval']);
        self::assertFalse($config['components']['redis']['enabled']);

        $kernel->shutdown();
    }

    public function testTheCacheRedisProviderIsPickedUp(): void
    {
        $kernel = new TestKernel([], ['cache' => ['default_redis_provider' => 'redis://cache.internal']]);
        $kernel->boot();

        self::assertSame('redis://cache.internal', $kernel->getContainer()->getParameter('jmonitor_easyadmin.framework_redis_provider'));

        $kernel->shutdown();
    }

    public function testWithoutACacheRedisProviderTheParameterIsAbsent(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        self::assertFalse($kernel->getContainer()->hasParameter('jmonitor_easyadmin.framework_redis_provider'));

        $kernel->shutdown();
    }
}
