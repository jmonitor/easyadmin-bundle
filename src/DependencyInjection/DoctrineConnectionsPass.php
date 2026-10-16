<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\DependencyInjection;

use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class DoctrineConnectionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MysqlDetector::class) || !$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var array<string, string> $connections name => service id */
        $connections = $container->getParameter('doctrine.connections');

        $references = [];
        foreach ($connections as $name => $serviceId) {
            $references[$name] = new Reference($serviceId);
        }

        $container->getDefinition(MysqlDetector::class)
            ->setArgument('$connections', ServiceLocatorTagPass::register($container, $references))
            ->setArgument('$connectionNames', array_keys($connections))
        ;
    }
}
