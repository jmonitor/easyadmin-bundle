<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle;

use Jmonitor\EasyAdminBundle\DependencyInjection\DoctrineConnectionsPass;
use Jmonitor\EasyAdminBundle\Detection\RedisDetector;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Scheduler;
use Symfony\Flex\SymfonyBundle;

final class JmonitorEasyAdminBundle extends AbstractBundle
{
    private const CONNECTION_DEPRECATION = 'The "connection" option of the mysql component is deprecated, use "connections" instead.';
    private const REDIS_OPTION_DEPRECATION = 'The "%s" option of the redis component is deprecated, use "connections" instead.';

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

    /**
     * @param array<string, mixed> $config
     */
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
                ->arrayNode('components')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->componentNode('system', 'Collected on Linux only when "auto".'))
                        ->append($this->componentNode('php', 'Always available when "auto": metrics of the PHP process serving the page.'))
                        ->append($this->componentNode('frankenphp', 'Detected when the page is served by FrankenPHP when "auto".', static function (NodeBuilder $children): void {
                            $children->scalarNode('endpoint')->defaultValue('http://localhost:2019/metrics')->cannotBeEmpty()->info('URL of the Caddy Prometheus endpoint, served by its admin API.');
                        }))
                        ->append($this->componentNode('symfony', 'Always available when "auto".', static function (NodeBuilder $children): void {
                            $children->booleanNode('messenger')->defaultValue(class_exists(Worker::class) && Kernel::VERSION_ID >= 70200)->info('Show the Messenger transports (runs "messenger:stats --format=json", Symfony 7.2+).');
                            $children->booleanNode('scheduler')->defaultValue(class_exists(Scheduler::class))->info('Show the Scheduler tasks (runs "debug:scheduler").');
                            $children->booleanNode('flex')->defaultValue(class_exists(SymfonyBundle::class))->info('Check for outdated Flex recipes (runs "composer recipes").');
                        }))
                        ->append($this->componentNode('mysql', 'Detected from the Doctrine DBAL connections when "auto".', static function (NodeBuilder $children): void {
                            // Kept for the config builders and their connection() method; declared without default and
                            // merged into "connections" below, it never reaches the processed configuration.
                            $children->scalarNode('connection')
                                ->setDeprecated('jmonitor/easyadmin-bundle', '1.1', self::CONNECTION_DEPRECATION)
                                ->info('Deprecated, use "connections".')
                            ;
                        }, self::connectionsNode('MySQL/MariaDB'), static fn(array $v): array => self::mergeDeprecatedConnection($v)))
                        ->append($this->componentNode('postgresql', 'Detected from the Doctrine DBAL connections when "auto".', static function (NodeBuilder $children): void {
                            $children->scalarNode('schema')->defaultValue('public')->cannotBeEmpty()->info('Schema whose tables feed the storage, scans and dead rows cards, for the connections without their own.');
                        }, self::connectionsNode('PostgreSQL', static function (NodeBuilder $children): void {
                            $children->scalarNode('schema')->defaultNull()->cannotBeEmpty()->info('Schema of this connection, defaults to the component "schema".');
                        })))
                        ->append($this->componentNode('redis', 'Detected from the Symfony cache configuration or a REDIS_URL env var when "auto".', static function (NodeBuilder $children): void {
                            // Kept for the config builders and their dsn() and service() methods; declared without
                            // default and merged into "connections" below, they never reach the processed configuration.
                            foreach (['dsn', 'service'] as $option) {
                                $children->scalarNode($option)
                                    ->setDeprecated('jmonitor/easyadmin-bundle', '1.1', sprintf(self::REDIS_OPTION_DEPRECATION, $option))
                                    ->info('Deprecated, use "connections".')
                                ;
                            }

                            $children->append(self::redisConnectionsNode());
                        }, merge: static fn(array $v): array => self::mergeDeprecatedRedisOptions($v)))
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * A component accepts "auto", true or false as a shorthand for its "enabled" key.
     *
     * @param (callable(NodeBuilder): void)|null                         $options
     * @param ?ArrayNodeDefinition                                        $connections the "connections" node of a database component
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $merge       applied to the component once every file is merged
     */
    private function componentNode(string $name, string $info, ?callable $options = null, ?ArrayNodeDefinition $connections = null, ?\Closure $merge = null): ArrayNodeDefinition
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

        if ($merge !== null) {
            $node
                ->validate()
                    ->always($merge)
                ->end()
            ;
        }

        $children = $node->children();
        $children->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto');

        if ($options !== null) {
            $options($children);
        }

        if ($connections !== null) {
            $children->append($connections);
        }

        return $node;
    }

    /**
     * "connections" lists the Doctrine connections to show: a list of names, a map name => options,
     * or a list of entries carrying a "name" key (the XML form).
     *
     * @param (callable(NodeBuilder): void)|null $options the options of one connection
     */
    private static function connectionsNode(string $product, ?callable $options = null): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('connections');
        $node
            ->info(sprintf('Doctrine connection names to show, empty for every %s connection found.', $product))
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->beforeNormalization()
                ->ifArray()
                ->then(static fn(array $v): array => array_is_list($v) ? array_map(static fn($entry) => is_string($entry) ? ['name' => $entry] : $entry, $v) : $v)
            ->end()
        ;

        $prototype = $node->arrayPrototype();

        if ($options !== null) {
            $options($prototype->children());
        }

        return $node;
    }

    /**
     * The former "connection" option of MySQL is an entry of "connections"; merged once every file is
     * merged, so that a later file overrides it and "connections" set in another file is refused.
     *
     * @param array<string, mixed> $component
     *
     * @return array<string, mixed>
     */
    private static function mergeDeprecatedConnection(array $component): array
    {
        $connection = $component['connection'] ?? null;
        unset($component['connection']);

        if ($connection === null) {
            return $component;
        }

        if ($component['connections'] !== []) {
            throw new InvalidConfigurationException('You cannot set both "connection" and "connections" for the mysql component.');
        }

        $component['connections'] = [$connection => []];

        return $component;
    }

    /**
     * "connections" maps an id to a Redis server: a DSN, {dsn} or {service}, or a list of entries
     * carrying a "name" key (the XML form, which gives a single entry as a bare map). A bare string
     * is only a DSN in the map form: in the list form it has no id.
     */
    private static function redisConnectionsNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('connections');
        $node
            ->info('Redis servers to show, keyed by an id: a DSN, or {dsn} or {service} (a \Redis, \Predis\Client or \Relay\Relay service). Empty to detect the Redis provider of the Symfony cache or REDIS_URL.')
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->beforeNormalization()
                ->ifArray()
                ->then(static function (array $v): array {
                    if (array_is_list($v)) {
                        foreach ($v as $entry) {
                            if (is_string($entry)) {
                                throw new InvalidConfigurationException('Redis connections need an id: use a map id => DSN, {dsn} or {service}.');
                            }
                        }

                        return $v;
                    }

                    // In the map form the value of a "name" id would be a DSN.
                    if (is_string($v['name'] ?? null) && !RedisDetector::isDsn($v['name']) && (is_string($v['dsn'] ?? null) || is_string($v['service'] ?? null))) {
                        return [$v];
                    }

                    return array_map(static fn($entry) => is_string($entry) ? ['dsn' => $entry] : $entry, $v);
                })
            ->end()
            ->validate()
                ->always(static function (array $connections): array {
                    foreach ($connections as $id => $connection) {
                        if ($connection['dsn'] === null && $connection['service'] === null) {
                            throw new InvalidConfigurationException(sprintf('The Redis connection "%s" needs a "dsn" or a "service".', $id));
                        }

                        if ($connection['dsn'] !== null && $connection['service'] !== null) {
                            throw new InvalidConfigurationException(sprintf('The Redis connection "%s" cannot have both a "dsn" and a "service".', $id));
                        }
                    }

                    return $connections;
                })
            ->end()
        ;

        $connection = $node->arrayPrototype()->children();
        $connection->scalarNode('dsn')->defaultNull()->info('Redis DSN, e.g. redis://localhost.');
        $connection->scalarNode('service')->defaultNull()->info('Service id of a \Redis, \Predis\Client or \Relay\Relay instance.');

        return $node;
    }

    /**
     * The former "dsn" and "service" options are the "default" entry of "connections"; merged once
     * every file is merged, so that a later file overrides them and "connections" set in another file
     * is refused.
     *
     * @param array<string, mixed> $component
     *
     * @return array<string, mixed>
     */
    private static function mergeDeprecatedRedisOptions(array $component): array
    {
        $set = [];

        foreach (['dsn', 'service'] as $option) {
            if (array_key_exists($option, $component)) {
                if ($component[$option] !== null) {
                    $set[$option] = $component[$option];
                }

                unset($component[$option]);
            }
        }

        if ($set === []) {
            return $component;
        }

        if (count($set) === 2) {
            throw new InvalidConfigurationException('You cannot set both "dsn" and "service" for the redis component. Please choose one.');
        }

        $option = array_key_first($set);

        if ($component['connections'] !== []) {
            throw new InvalidConfigurationException(sprintf('You cannot set both "%s" and "connections" for the redis component.', $option));
        }

        $component['connections'] = ['default' => [$option => $set[$option]] + ['dsn' => null, 'service' => null]];

        return $component;
    }
}
