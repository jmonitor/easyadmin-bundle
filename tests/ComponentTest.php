<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests;

use Jmonitor\EasyAdminBundle\Component;
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

    public function testRouteRequirementListsEveryComponent(): void
    {
        self::assertSame('system|php|symfony|mysql|redis', Component::routeRequirement());
    }
}
