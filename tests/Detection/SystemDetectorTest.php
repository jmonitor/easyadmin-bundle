<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\Collector\System\Adapter\RandomAdapter;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\SystemDetector;
use PHPUnit\Framework\TestCase;

final class SystemDetectorTest extends TestCase
{
    public function testLinuxIsDetected(): void
    {
        $detection = (new SystemDetector('Linux', new RandomAdapter()))->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(Component::System, $detection->collectors?->component);
        self::assertSame(['system'], $detection->collectors->names());
        self::assertSame(Lifetime::Dynamic, $detection->collectors->get('system')?->lifetime);
        self::assertInstanceOf(SystemCollector::class, $detection->collectors->get('system')->collector);
    }

    public function testOtherFamiliesAreUnavailableWithoutAnExplicitAdapter(): void
    {
        $detection = (new SystemDetector('Darwin'))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('Darwin', (string) $detection->error);
    }

    public function testAnExplicitAdapterWorksOnAnyFamily(): void
    {
        self::assertTrue((new SystemDetector('Windows', new RandomAdapter()))->detect()->isFound());
    }
}
