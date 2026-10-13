<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;
use PHPUnit\Framework\TestCase;

final class CollectorSetTest extends TestCase
{
    public function testSpecTakesItsNameFromTheCollector(): void
    {
        $spec = new CollectorSpec(new FakeCollector('mysql.status'), Lifetime::Dynamic, trackDelta: true);

        self::assertSame('mysql.status', $spec->name);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
    }

    public function testSetExposesNamesAndLookup(): void
    {
        $status = new CollectorSpec(new FakeCollector('mysql.status'), Lifetime::Dynamic);
        $variables = new CollectorSpec(new FakeCollector('mysql.variables'), Lifetime::Static);
        $set = new CollectorSet(Component::Mysql, [$status, $variables]);

        self::assertSame(Component::Mysql, $set->component);
        self::assertSame(['mysql.status', 'mysql.variables'], $set->names());
        self::assertSame($variables, $set->get('mysql.variables'));
        self::assertNull($set->get('mysql.slow_queries'));
    }
}
