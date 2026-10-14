<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle;

use Jmonitor\EasyAdminBundle\DependencyInjection\DoctrineConnectionsPass;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Scheduler;
use Symfony\Flex\SymfonyBundle;

final class JmonitorEasyAdminBundle extends AbstractBundle
{
    protected string $extensionAlias = 'jmonitor_easyadmin';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new DoctrineConnectionsPass());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach ($builder->getExtensionConfig('framework') as $frameworkConfig) {
            $provider = $frameworkConfig['cache']['default_redis_provider'] ?? null;

            if (is_string($provider) && $provider !== '') {
                $builder->setParameter('jmonitor_easyadmin.framework_redis_provider', $provider);
            }
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('jmonitor_easyadmin.config', $config);

        $container->import('../config/services.php');
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('cache_pool')
                    ->defaultValue('cache.app')
                    ->cannotBeEmpty()
                    ->info('Service id of the PSR-6 cache pool that keeps the collected metrics.')
                ->end()
                ->integerNode('polling_interval')
                    ->defaultValue(10)
                    ->min(0)
                    ->info('Seconds between two live refreshes of a page, also the lifetime of fast-changing metrics. 0 disables the live refresh.')
                ->end()
                ->integerNode('static_ttl')
                    ->defaultValue(3600)
                    ->min(1)
                    ->info('Seconds during which rarely changing data (versions, configuration, OS) is kept in cache.')
                ->end()
                ->scalarNode('role')
                    ->defaultValue('ROLE_ADMIN')
                    ->cannotBeEmpty()
                    ->info('Role required to open the monitoring pages.')
                ->end()
                ->scalarNode('help_url')
                    ->defaultValue('https://dash.jmonitor.io/help/metric/')
                    ->info('Base URL of the public metric help pages. Set to false to hide the help links.')
                    ->validate()
                        ->ifTrue(static fn($v): bool => $v !== false && (!is_string($v) || $v === ''))
                        ->thenInvalid('help_url must be a non-empty URL or false.')
                    ->end()
                ->end()
                ->arrayNode('components')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->componentNode('system', 'Collected on Linux only when "auto".'))
                        ->append($this->componentNode('php', 'Always available when "auto": metrics of the PHP process serving the page.'))
                        ->append($this->componentNode('symfony', 'Always available when "auto".', static function (NodeBuilder $children): void {
                            $children->booleanNode('messenger')->defaultValue(class_exists(Worker::class))->info('Show the Messenger transports (runs "messenger:stats").');
                            $children->booleanNode('scheduler')->defaultValue(class_exists(Scheduler::class))->info('Show the Scheduler tasks (runs "debug:scheduler").');
                            $children->booleanNode('flex')->defaultValue(class_exists(SymfonyBundle::class))->info('Check for outdated Flex recipes (runs "composer recipes").');
                        }))
                        ->append($this->componentNode('mysql', 'Detected from the Doctrine DBAL connections when "auto".', static function (NodeBuilder $children): void {
                            $children->scalarNode('connection')->defaultNull()->info('Doctrine connection name (e.g. "default"). Defaults to the first MySQL/MariaDB connection found.');
                        }))
                        ->append($this->componentNode('redis', 'Detected from the Symfony cache configuration or a REDIS_URL env var when "auto".', static function (NodeBuilder $children): void {
                            $children->scalarNode('dsn')->defaultNull()->info('Redis DSN, e.g. redis://localhost.');
                            $children->scalarNode('service')->defaultNull()->info('Service id of a \Redis, \Predis\Client or \Relay\Relay instance.');
                        }))
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn(array $config): bool => !empty($config['components']['redis']['dsn']) && !empty($config['components']['redis']['service']))
                ->thenInvalid('You cannot set both "dsn" and "service" for the redis component. Please choose one.')
            ->end()
        ;
    }

    /**
     * A component accepts "auto", true or false as a shorthand for its "enabled" key.
     *
     * @param (callable(NodeBuilder): void)|null $options
     */
    private function componentNode(string $name, string $info, ?callable $options = null): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->info($info)
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                ->ifTrue(static fn($v): bool => is_bool($v) || $v === 'auto')
                ->then(static fn($v): array => ['enabled' => $v])
            ->end()
        ;

        $children = $node->children();
        $children->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto');

        if ($options !== null) {
            $options($children);
        }

        return $node;
    }
}
