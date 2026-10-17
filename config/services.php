<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Controller\MonitoringController;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Detection\PhpDetector;
use Jmonitor\EasyAdminBundle\Detection\PostgresqlDetector;
use Jmonitor\EasyAdminBundle\Detection\RedisDetector;
use Jmonitor\EasyAdminBundle\Detection\SymfonyDetector;
use Jmonitor\EasyAdminBundle\Detection\SystemDetector;
use Jmonitor\EasyAdminBundle\Logging\MinimumLevelLogger;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\DeltaCalculator;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\Twig\MonitoringExtension;
use Jmonitor\EasyAdminBundle\View\ViewFactory;
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
            '$configured' => $config['components']['mysql']['connections'],
            '$logger' => service(MinimumLevelLogger::class),
        ])
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::Mysql->value])
    ;

    $services->set(PostgresqlDetector::class)
        ->args([
            '$connections' => service_locator([]),
            '$connectionNames' => [],
            '$configured' => $config['components']['postgresql']['connections'],
            '$schema' => $config['components']['postgresql']['schema'],
            '$logger' => service(MinimumLevelLogger::class),
        ])
        ->tag('jmonitor_easyadmin.detector', ['index' => Component::Postgresql->value])
    ;

    $redisConfig = $config['components']['redis'];
    $redisProvider = $builder->hasParameter('jmonitor_easyadmin.framework_redis_provider') ? $builder->getParameter('jmonitor_easyadmin.framework_redis_provider') : null;
    $redisServices = [];

    foreach ($redisConfig['connections'] as $connection) {
        if ($connection['service'] !== null) {
            $redisServices[$connection['service']] = service($connection['service'])->ignoreOnInvalid();
        }
    }

    if (is_string($redisProvider) && !str_starts_with($redisProvider, '%') && !RedisDetector::isDsn($redisProvider)) {
        $redisServices[$redisProvider] = service($redisProvider)->ignoreOnInvalid();
    }

    $services->set(RedisDetector::class)
        ->args([
            service_locator($redisServices),
            $redisConfig['connections'],
            $redisProvider !== null ? '%jmonitor_easyadmin.framework_redis_provider%' : null,
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

    $services->set(MinimumLevelLogger::class)
        ->args([service('logger')->nullOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'jmonitor_easyadmin'])
    ;

    $services->set(CollectorRunner::class)
        ->args([service(MinimumLevelLogger::class)])
    ;

    $services->set(DeltaCalculator::class)
        ->args([
            service($config['cache_pool']),
            max(1, intdiv($config['polling_interval'], 2)),
            max(300, 2 * $config['polling_interval']),
        ])
    ;

    $services->set(SnapshotProvider::class)
        ->args([
            service(CollectorRunner::class),
            service(DeltaCalculator::class),
            service($config['cache_pool']),
            service('clock'),
            $config['polling_interval'],
            $config['static_ttl'],
        ])
    ;
    $services->set(ViewFactory::class)
        ->args([
            service(GaugeFactory::class),
            service(SnapshotProvider::class),
            service(ComponentRegistry::class),
            service('clock'),
            $config['components']['symfony']['messenger'],
            $config['components']['symfony']['scheduler'],
            $config['components']['symfony']['flex'],
        ])
    ;
    $services->set(MonitoringExtension::class)
        ->tag('twig.extension')
    ;
    $services->set(MonitoringController::class)
        ->args([
            service(ComponentRegistry::class),
            service(SnapshotProvider::class),
            service(ViewFactory::class),
            service('twig'),
            service(AdminContextProviderInterface::class),
            service('router'),
            $config['polling_interval'],
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments')
        ->tag('ea.admin_route_controller')
    ;
};
