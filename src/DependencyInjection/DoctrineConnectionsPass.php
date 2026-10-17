<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\DependencyInjection;

use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the Doctrine connections, by name, to the detectors that read their parameters.
 */
final class DoctrineConnectionsPass implements CompilerPassInterface
{
    private const DETECTORS = [MysqlDetector::class, 'Jmonitor\EasyAdminBundle\Detection\PostgresqlDetector'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var array<string, string> $connections name => service id */
        $connections = $container->getParameter('doctrine.connections');

        $references = [];
        foreach ($connections as $name => $serviceId) {
            $references[$name] = new Reference($serviceId);
        }

        foreach (self::DETECTORS as $detector) {
            if (!$container->hasDefinition($detector)) {
                continue;
            }

            $container->getDefinition($detector)
                ->setArgument('$connections', ServiceLocatorTagPass::register($container, $references))
                ->setArgument('$connectionNames', array_keys($connections))
            ;
        }
    }
}
