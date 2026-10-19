<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\DependencyInjection;

use Jmonitor\EasyAdminBundle\DependencyInjection\DoctrineConnectionsPass;
use Jmonitor\EasyAdminBundle\Detection\DoctrineConnections;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DoctrineConnectionsPassTest extends TestCase
{
    public function testConnectionsAreExposedByName(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'reporting' => 'doctrine.dbal.reporting_connection', 2024 => 'doctrine.dbal.2024_connection']);
        $container->setDefinition('doctrine.dbal.default_connection', new Definition(\stdClass::class));
        $container->setDefinition('doctrine.dbal.reporting_connection', new Definition(\stdClass::class));
        $container->setDefinition('doctrine.dbal.2024_connection', new Definition(\stdClass::class));
        $container->setDefinition(DoctrineConnections::class, $this->connections());

        (new DoctrineConnectionsPass())->process($container);

        $definition = $container->getDefinition(DoctrineConnections::class);
        self::assertSame(['default', 'reporting', '2024'], $definition->getArgument('$names'));
        self::assertInstanceOf(Reference::class, $definition->getArgument('$connections'));
    }

    public function testWithoutDoctrineNothingChanges(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(DoctrineConnections::class, $this->connections());

        (new DoctrineConnectionsPass())->process($container);

        self::assertSame([], $container->getDefinition(DoctrineConnections::class)->getArgument('$names'));
    }

    private function connections(): Definition
    {
        return (new Definition(DoctrineConnections::class))->setArguments(['$connections' => null, '$names' => []]);
    }
}
