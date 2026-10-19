<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Detection\Server;
use Jmonitor\EasyAdminBundle\View\InstanceNavigation;
use Jmonitor\EasyAdminBundle\View\NavigationItem;
use PHPUnit\Framework\TestCase;

final class InstanceNavigationTest extends TestCase
{
    public function testASingleInstanceHasNoPillsAndNoSelector(): void
    {
        $only = $this->database('default', 'db1');

        $navigation = InstanceNavigation::of([$only], $only);

        self::assertSame([], $navigation->pills);
        self::assertSame([$only], $navigation->databases);
        self::assertNull($navigation->databaseSelector);
    }

    public function testTwoDatabasesOfOneServerUsePills(): void
    {
        $default = $this->database('default', 'db1');
        $reports = $this->database('reports', 'db1');

        $navigation = InstanceNavigation::of([$default, $reports], $reports);

        self::assertSame([], $navigation->pills);
        self::assertSame([$default, $reports], $navigation->databases);
        self::assertSame('pills', $navigation->databaseSelector);
    }

    public function testThreeDatabasesOfOneServerUseASelect(): void
    {
        $instances = [$this->database('a', 'db1'), $this->database('b', 'db1'), $this->database('c', 'db1')];

        self::assertSame('select', InstanceNavigation::of($instances, $instances[0])->databaseSelector);
    }

    public function testEachServerGetsAPillLabelledWithItsHost(): void
    {
        $default = $this->database('default', 'db1');
        $reports = $this->database('reports', 'db2');
        $legacy = $this->database('legacy', 'db2');

        $navigation = InstanceNavigation::of([$default, $reports, $legacy], $legacy);

        self::assertSame(['db1', 'db2'], array_map(static fn($item) => $item->label, $navigation->pills));
        self::assertSame([false, true], array_map(static fn($item) => $item->active, $navigation->pills));
        self::assertSame($reports, $navigation->pills[1]->target);
        self::assertNull($navigation->pills[1]->detail);
        self::assertSame([$reports, $legacy], $navigation->databases);
        self::assertSame('pills', $navigation->databaseSelector);
    }

    public function testServersSharingAHostAreToldApartByPort(): void
    {
        $first = $this->database('a', 'localhost', 3306);
        $second = $this->database('b', 'localhost', 3307);

        $navigation = InstanceNavigation::of([$first, $second], $first);

        self::assertSame(['localhost:3306', 'localhost:3307'], array_map(static fn($item) => $item->label, $navigation->pills));
    }

    public function testSocketServersSharingAHostAreToldApartBySocket(): void
    {
        $socket = static fn(string $id, string $path): Instance => Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), $id, null, new Server('socket:' . $path, 'localhost', null, $path));
        $first = $socket('a', '/run/mysqld/a.sock');
        $second = $socket('b', '/run/mysqld/b.sock');
        $tcp = $this->database('c', 'localhost');

        $navigation = InstanceNavigation::of([$first, $second, $tcp], $first);

        self::assertSame(['/run/mysqld/a.sock', '/run/mysqld/b.sock', 'localhost:3306'], array_map(static fn($item) => $item->label, $navigation->pills));
    }

    public function testInstancesWithoutServerKeepTheirOwnPill(): void
    {
        $default = Instance::found(Component::Postgresql, new CollectorSet(Component::Postgresql, []), 'default', 'shop');
        $dwh = Instance::found(Component::Postgresql, new CollectorSet(Component::Postgresql, []), 'dwh', 'warehouse');

        $navigation = InstanceNavigation::of([$default, $dwh], $dwh);

        self::assertSame(['default', 'dwh'], array_map(static fn($item) => $item->label, $navigation->pills));
        self::assertSame(['shop', 'warehouse'], array_map(static fn($item) => $item->detail, $navigation->pills));
        self::assertSame([false, true], array_map(static fn($item) => $item->active, $navigation->pills));
        self::assertSame([], $navigation->databases);
        self::assertNull($navigation->databaseSelector);
    }

    public function testAnInstanceInErrorBesideAServerGetsItsOwnPill(): void
    {
        $default = $this->database('default', 'db1');
        $broken = Instance::unavailable(Component::Mysql, 'Unknown Doctrine connection "broken".', 'broken');

        $navigation = InstanceNavigation::of([$default, $broken], $default);

        self::assertCount(2, $navigation->pills);
        self::assertSame('broken', $navigation->pills[1]->label);
        self::assertSame('Unknown Doctrine connection "broken".', $navigation->pills[1]->error);
    }

    public function testWithoutCurrentInstanceThereIsNothingToPick(): void
    {
        $navigation = InstanceNavigation::of([], null);

        self::assertSame([], $navigation->pills);
        self::assertSame([], $navigation->databases);
        self::assertNull($navigation->databaseSelector);
    }

    public function testACurrentInstanceMissingFromTheListActivatesNothing(): void
    {
        $navigation = InstanceNavigation::of([$this->database('shop', 'db1'), $this->database('erp', 'db2')], $this->database('shop', 'db1'));

        self::assertCount(2, $navigation->pills);
        self::assertSame([false, false], array_map(static fn(NavigationItem $item): bool => $item->active, $navigation->pills));
        self::assertSame([], $navigation->databases);
        self::assertNull($navigation->databaseSelector);
    }

    private function database(string $id, string $host, int $port = 3306): Instance
    {
        return Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), $id, null, new Server($host . ':' . $port, $host, $port));
    }
}
