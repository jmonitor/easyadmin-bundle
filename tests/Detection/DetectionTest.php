<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Detection;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use PHPUnit\Framework\TestCase;

final class DetectionTest extends TestCase
{
    public function testFoundWrapsTheSetInOneImplicitInstance(): void
    {
        $set = new CollectorSet(Component::Redis, []);

        $detection = Detection::found($set);

        self::assertTrue($detection->isFound());
        self::assertCount(1, $detection->instances);
        self::assertSame($set, $detection->instances[0]->collectors);
        self::assertSame(Component::Redis, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id);
        self::assertNull($detection->error);
    }

    public function testInstancesKeepsTheGivenOrder(): void
    {
        $detection = Detection::instances([
            Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'default', 'shop'),
            Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'legacy', 'erp'),
        ]);

        self::assertTrue($detection->isFound());
        self::assertSame(['default', 'legacy'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
    }

    public function testUnavailableHasNoInstanceAndAReason(): void
    {
        $detection = Detection::unavailable('No MySQL or MariaDB Doctrine connection found.');

        self::assertFalse($detection->isFound());
        self::assertSame([], $detection->instances);
        self::assertSame('No MySQL or MariaDB Doctrine connection found.', $detection->error);
    }
}
