<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\DependencyInjection;

use Jmonitor\EasyAdminBundle\DependencyInjection\DoctrineConnectionsPass;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DoctrineConnectionsPassTest extends TestCase
{
    public function testConnectionsAreExposedByName(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'reporting' => 'doctrine.dbal.reporting_connection']);
        $container->setDefinition('doctrine.dbal.default_connection', new Definition(\stdClass::class));
        $container->setDefinition('doctrine.dbal.reporting_connection', new Definition(\stdClass::class));
        $container->setDefinition(MysqlDetector::class, (new Definition(MysqlDetector::class))->setArguments(['$connections' => null, '$connectionNames' => [], '$connection' => null]));

        (new DoctrineConnectionsPass())->process($container);

        $definition = $container->getDefinition(MysqlDetector::class);
        self::assertSame(['default', 'reporting'], $definition->getArgument('$connectionNames'));
        self::assertInstanceOf(Reference::class, $definition->getArgument('$connections'));
    }

    public function testWithoutDoctrineNothingChanges(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MysqlDetector::class, (new Definition(MysqlDetector::class))->setArguments(['$connections' => null, '$connectionNames' => [], '$connection' => null]));

        (new DoctrineConnectionsPass())->process($container);

        self::assertSame([], $container->getDefinition(MysqlDetector::class)->getArgument('$connectionNames'));
    }
}
