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

    public function testSpecIsNotPerServerByDefault(): void
    {
        self::assertFalse((new CollectorSpec(new FakeCollector('a'), Lifetime::Static))->perServer);
        self::assertTrue((new CollectorSpec(new FakeCollector('a'), Lifetime::Static, perServer: true))->perServer);
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

    public function testALazySetIsBuiltOnFirstUseOnly(): void
    {
        $builds = 0;
        $status = new CollectorSpec(new FakeCollector('mysql.status'), Lifetime::Dynamic);
        $set = CollectorSet::lazy(Component::Mysql, static function () use (&$builds, $status): CollectorSet {
            ++$builds;

            return new CollectorSet(Component::Mysql, [$status]);
        });

        self::assertSame(0, $builds);
        self::assertSame(['mysql.status'], $set->names());
        self::assertSame($status, $set->get('mysql.status'));
        self::assertSame(1, $builds);
    }

    public function testReleaseReachesTheBuiltSetOnly(): void
    {
        $releases = 0;
        $release = static function () use (&$releases): void {
            ++$releases;
        };
        $set = CollectorSet::lazy(Component::Mysql, static fn(): CollectorSet => new CollectorSet(Component::Mysql, [], $release));

        $set->release();
        self::assertSame(0, $releases, 'nothing was built, so nothing was opened');

        $set->specs();
        $set->release();
        self::assertSame(1, $releases);
    }
}
