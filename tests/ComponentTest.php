<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Controller\MonitoringController;
use PHPUnit\Framework\TestCase;

final class ComponentTest extends TestCase
{
    public function testLabels(): void
    {
        self::assertSame('System', Component::System->label());
        self::assertSame('PHP', Component::Php->label());
        self::assertSame('FrankenPHP', Component::FrankenPhp->label());
        self::assertSame('Apache', Component::Apache->label());
        self::assertSame('Nginx', Component::Nginx->label());
        self::assertSame('Symfony', Component::Symfony->label());
        self::assertSame('MySQL', Component::Mysql->label());
        self::assertSame('PostgreSQL', Component::Postgresql->label());
        self::assertSame('Redis', Component::Redis->label());
    }

    public function testFrankenPhpComesRightAfterPhp(): void
    {
        $cases = Component::cases();

        self::assertSame(array_search(Component::Php, $cases, true) + 1, array_search(Component::FrankenPhp, $cases, true), 'the enum order is the tab order');
    }

    public function testApacheComesRightAfterFrankenPhp(): void
    {
        $cases = Component::cases();

        self::assertSame(array_search(Component::FrankenPhp, $cases, true) + 1, array_search(Component::Apache, $cases, true), 'the enum order is the tab order');
    }

    public function testNginxComesRightAfterApache(): void
    {
        $cases = Component::cases();

        self::assertSame(array_search(Component::Apache, $cases, true) + 1, array_search(Component::Nginx, $cases, true), 'the enum order is the tab order');
    }

    public function testTheControllerRouteRequirementListsEveryComponent(): void
    {
        $options = (new \ReflectionClassConstant(MonitoringController::class, 'COMPONENT_OPTIONS'))->getValue();

        self::assertSame(implode('|', array_map(static fn(Component $c): string => $c->value, Component::cases())), $options['requirements']['component']);
    }
}
