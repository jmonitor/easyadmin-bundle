<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

final class BundleBootTest extends TestCase
{
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
}
