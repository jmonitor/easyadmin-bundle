<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    /** @var array<string, mixed> $config */
    $config = $builder->getParameter('jmonitor_easyadmin.config');

    $services = $container->services();

    $services->defaults()
        ->autowire(false)
        ->autoconfigure(false)
    ;
};
