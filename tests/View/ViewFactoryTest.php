<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Detection;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\DeltaCalculator;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\StaticDetector;
use Jmonitor\EasyAdminBundle\View\MysqlView;
use Jmonitor\EasyAdminBundle\View\PhpView;
use Jmonitor\EasyAdminBundle\View\PostgresqlView;
use Jmonitor\EasyAdminBundle\View\RedisView;
use Jmonitor\EasyAdminBundle\View\SymfonyView;
use Jmonitor\EasyAdminBundle\View\SystemView;
use Jmonitor\EasyAdminBundle\View\ViewFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ViewFactoryTest extends TestCase
{
    public function testEachComponentHasItsView(): void
    {
        $registry = new ComponentRegistry([
            'php' => new StaticDetector(Component::Php, Detection::found(new CollectorSet(Component::Php, [new CollectorSpec(new FakeCollector('php', ['version' => '8.4.0']), Lifetime::Dynamic)]))),
            'symfony' => new StaticDetector(Component::Symfony, Detection::found(new CollectorSet(Component::Symfony, [new CollectorSpec(new FakeCollector('symfony', ['env' => 'test']), Lifetime::Static)]))),
        ], ['php' => ['enabled' => 'auto'], 'symfony' => ['enabled' => 'auto']]);
        $cache = new ArrayAdapter();
        $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 5), $cache, new MockClock(), 10, 3600);
        $factory = new ViewFactory(new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php')), $snapshots, $registry, new MockClock());
        $snapshot = static fn(Component $component): ComponentSnapshot => $snapshots->get($registry->instance($component, null) ?? Instance::unavailable($component, 'not detected'));

        self::assertInstanceOf(SystemView::class, $factory->create(Component::System, $snapshot(Component::System)));
        self::assertInstanceOf(PhpView::class, $factory->create(Component::Php, $snapshot(Component::Php)));
        self::assertInstanceOf(MysqlView::class, $factory->create(Component::Mysql, $snapshot(Component::Mysql)));
        self::assertInstanceOf(PostgresqlView::class, $factory->create(Component::Postgresql, $snapshot(Component::Postgresql)));
        self::assertInstanceOf(RedisView::class, $factory->create(Component::Redis, $snapshot(Component::Redis)));

        $symfony = $factory->create(Component::Symfony, $snapshot(Component::Symfony));
        self::assertInstanceOf(SymfonyView::class, $symfony);
        self::assertSame('TEST', $symfony->environment);
        self::assertTrue($symfony->recommendationsAvailable, 'the Symfony view reads the PHP snapshot');
    }
}
