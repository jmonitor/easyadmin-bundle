<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use PHPUnit\Framework\TestCase;

final class InstanceTest extends TestCase
{
    public function testTheImplicitInstanceHasNoKeyPrefix(): void
    {
        $instance = Instance::found(Component::Php, new CollectorSet(Component::Php, []));

        self::assertNull($instance->id);
        self::assertNull($instance->detail);
        self::assertSame('', $instance->keyPrefix());
        self::assertTrue($instance->isFound());
    }

    public function testANamedInstancePrefixesItsKeysWithTheComponentAndItsId(): void
    {
        $instance = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'legacy', 'erp');

        self::assertSame('legacy', $instance->id);
        self::assertSame('erp', $instance->detail);
        self::assertSame('mysql.legacy.', $instance->keyPrefix());
    }

    public function testAnUnavailableInstanceKeepsItsIdentityAndError(): void
    {
        $instance = Instance::unavailable(Component::Postgresql, 'Unknown Doctrine connection "dwh".', 'dwh');

        self::assertFalse($instance->isFound());
        self::assertNull($instance->collectors);
        self::assertSame('Unknown Doctrine connection "dwh".', $instance->error);
        self::assertSame('postgresql.dwh.', $instance->keyPrefix());
    }
}
