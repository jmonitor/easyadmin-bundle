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
        self::assertSame('Symfony', Component::Symfony->label());
        self::assertSame('MySQL', Component::Mysql->label());
        self::assertSame('Redis', Component::Redis->label());
    }

    public function testTheControllerRouteRequirementListsEveryComponent(): void
    {
        $options = (new \ReflectionClassConstant(MonitoringController::class, 'COMPONENT_OPTIONS'))->getValue();

        self::assertSame(implode('|', array_map(static fn(Component $c): string => $c->value, Component::cases())), $options['requirements']['component']);
    }
}
