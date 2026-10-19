<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\DependencyInjection;

use Jmonitor\EasyAdminBundle\Detection\DoctrineConnections;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the Doctrine connections, by name, to the service the detectors read them through.
 */
final class DoctrineConnectionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections') || !$container->hasDefinition(DoctrineConnections::class)) {
            return;
        }

        /** @var array<string, string> $connections name => service id */
        $connections = $container->getParameter('doctrine.connections');

        $references = [];
        foreach ($connections as $name => $serviceId) {
            $references[$name] = new Reference($serviceId);
        }

        $container->getDefinition(DoctrineConnections::class)
            ->setArgument('$connections', ServiceLocatorTagPass::register($container, $references))
            ->setArgument('$names', array_map(strval(...), array_keys($connections)))
        ;
    }
}
