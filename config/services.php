<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\PhpDetector;
use Jmonitor\EasyAdminBundle\Detection\SystemDetector;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    /** @var array<string, mixed> $config */
    $config = $builder->getParameter('jmonitor_easyadmin.config');

    $services = $container->services();

    $services->defaults()
        ->autowire(false)
        ->autoconfigure(false)
    ;

    $services->set(SystemDetector::class)
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::System->value])
    ;

    $services->set(PhpDetector::class)
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::Php->value])
    ;

    $services->set(ComponentRegistry::class)
        ->args([
            tagged_iterator('jmonitor_easyadmin.detector', 'index'),
            $config['components'],
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
};
