<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\DependencyInjection;

use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Detection\PostgresqlDetector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the Doctrine connections, by name, to the detectors that read their parameters.
 */
final class DoctrineConnectionsPass implements CompilerPassInterface
{
    private const DETECTORS = [MysqlDetector::class, PostgresqlDetector::class];

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

        $locator = ServiceLocatorTagPass::register($container, $references);

        foreach (self::DETECTORS as $detector) {
            if (!$container->hasDefinition($detector)) {
                continue;
            }

            $container->getDefinition($detector)
                ->setArgument('$connections', $locator)
                ->setArgument('$connectionNames', array_keys($connections))
            ;
        }
    }
}
