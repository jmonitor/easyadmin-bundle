<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Detection\Server;
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

    public function testTheKeyPrefixIsSafeForAPsr6CacheWhateverTheConnectionName(): void
    {
        $instance = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'app:read/replica');

        self::assertSame('mysql.app%3Aread%2Freplica.', $instance->keyPrefix());
        self::assertSame(0, preg_match('#[{}()/\\\\@:]#', $instance->keyPrefix()));
    }

    public function testAnUnavailableInstanceKeepsItsIdentityAndError(): void
    {
        $instance = Instance::unavailable(Component::Postgresql, 'Unknown Doctrine connection "dwh".', 'dwh');

        self::assertFalse($instance->isFound());
        self::assertNull($instance->collectors);
        self::assertSame('Unknown Doctrine connection "dwh".', $instance->error);
        self::assertSame('postgresql.dwh.', $instance->keyPrefix());
    }

    public function testTheServerPrefixNeverCollidesWithAConnectionPrefix(): void
    {
        $instance = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), '%server', null, new Server('x', 'localhost', 3306));

        self::assertSame('mysql.%25server.', $instance->keyPrefix());
        self::assertFalse(str_starts_with($instance->keyPrefix(), 'mysql.%server.'));
        self::assertSame('mysql.%server.' . hash('xxh128', 'x') . '.', $instance->serverKeyPrefix());
    }

    public function testThereIsNoServerPrefixWithoutServer(): void
    {
        self::assertNull(Instance::unavailable(Component::Mysql, 'error', 'a')->serverKeyPrefix());
        self::assertNull(Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'a')->serverKeyPrefix());
    }
}
