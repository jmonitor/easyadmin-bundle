<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Detection\PhpDetector;
use Jmonitor\EasyAdminBundle\Detection\RedisDetector;
use Jmonitor\EasyAdminBundle\Detection\SymfonyDetector;
use Jmonitor\EasyAdminBundle\Detection\SystemDetector;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\DeltaCalculator;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
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

    $services->set(SymfonyDetector::class)
        ->args([
            service('kernel'),
            $config['components']['symfony']['messenger'],
            $config['components']['symfony']['scheduler'],
            $config['components']['symfony']['flex'],
        ])
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::Symfony->value])
    ;

    $services->set(MysqlDetector::class)
        ->args([
            '$connections' => service_locator([]),
            '$connectionNames' => [],
            '$connection' => $config['components']['mysql']['connection'],
        ])
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::Mysql->value])
    ;

    $redisConfig = $config['components']['redis'];

    $services->set(RedisDetector::class)
        ->args([
            $redisConfig['service'] !== null ? service_locator([$redisConfig['service'] => service($redisConfig['service'])]) : service_locator([]),
            $redisConfig['service'],
            $redisConfig['dsn'],
            $builder->hasParameter('jmonitor_easyadmin.framework_redis_provider') ? '%jmonitor_easyadmin.framework_redis_provider%' : null,
            env('default::REDIS_URL'),
        ])
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::Redis->value])
    ;

    $services->set(ComponentRegistry::class)
        ->args([
            tagged_iterator('jmonitor_easyadmin.detector', 'index'),
            $config['components'],
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;

    $services->set(Ranges::class)
        ->factory([Ranges::class, 'fromFile'])
        ->args([__DIR__ . '/ranges.php'])
    ;

    $services->set(GaugeFactory::class)
        ->args([service(Ranges::class)])
    ;

    $services->set(CollectorRunner::class);

    $services->set(DeltaCalculator::class)
        ->args([
            service($config['cache_pool']),
            max(1, intdiv($config['polling_interval'], 2)),
        ])
    ;

    $services->set(SnapshotProvider::class)
        ->args([
            service(ComponentRegistry::class),
            service(CollectorRunner::class),
            service(DeltaCalculator::class),
            service($config['cache_pool']),
            service('clock'),
            $config['polling_interval'],
            $config['static_ttl'],
        ])
    ;
};
