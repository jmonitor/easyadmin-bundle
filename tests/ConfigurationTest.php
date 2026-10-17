<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests;

use Jmonitor\EasyAdminBundle\JmonitorEasyAdminBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame('cache.app', $config['cache_pool']);
        self::assertSame(10, $config['polling_interval']);
        self::assertSame(3600, $config['static_ttl']);

        foreach (['system', 'php', 'symfony', 'mysql', 'postgresql', 'redis'] as $component) {
            self::assertSame('auto', $config['components'][$component]['enabled'], $component);
        }

        self::assertSame([], $config['components']['mysql']['connections']);
        self::assertSame([], $config['components']['postgresql']['connections']);
        self::assertArrayNotHasKey('connection', $config['components']['mysql']);
        self::assertSame('public', $config['components']['postgresql']['schema']);
        self::assertSame([], $config['components']['redis']['connections']);
        self::assertArrayNotHasKey('dsn', $config['components']['redis']);
        self::assertArrayNotHasKey('service', $config['components']['redis']);
        self::assertIsBool($config['components']['symfony']['messenger']);
    }

    #[DataProvider('provideComponentShorthands')]
    public function testComponentShorthand(mixed $shorthand, mixed $expectedEnabled): void
    {
        $config = $this->process(['components' => ['mysql' => $shorthand]]);

        self::assertSame($expectedEnabled, $config['components']['mysql']['enabled']);
    }

    public static function provideComponentShorthands(): iterable
    {
        yield 'auto' => ['auto', 'auto'];
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'explicit array' => [['enabled' => false], false];
    }

    public function testExplicitComponentOptions(): void
    {
        $config = $this->process(['components' => [
            'mysql' => ['connections' => ['default', 'legacy']],
            'postgresql' => ['connections' => ['default' => null, 'dwh' => ['schema' => 'analytics']], 'schema' => 'app'],
            'redis' => ['connections' => ['cache' => 'redis://cache', 'sessions' => ['dsn' => 'redis://sessions:6379/1'], 'queue' => ['service' => 'app.redis_queue']]],
            'symfony' => ['flex' => false],
        ]]);

        self::assertSame('auto', $config['components']['mysql']['enabled']);
        self::assertSame(['default' => [], 'legacy' => []], $config['components']['mysql']['connections']);
        self::assertSame(['default' => ['schema' => null], 'dwh' => ['schema' => 'analytics']], $config['components']['postgresql']['connections']);
        self::assertSame('app', $config['components']['postgresql']['schema']);
        self::assertEquals([
            'cache' => ['dsn' => 'redis://cache', 'service' => null],
            'sessions' => ['dsn' => 'redis://sessions:6379/1', 'service' => null],
            'queue' => ['dsn' => null, 'service' => 'app.redis_queue'],
        ], $config['components']['redis']['connections']);
        self::assertFalse($config['components']['symfony']['flex']);
    }

    public function testConnectionsAcceptsAListOfNamedEntries(): void
    {
        $config = $this->process(['components' => ['postgresql' => ['connections' => [['name' => 'default'], ['name' => 'dwh', 'schema' => 'analytics']]]]]);

        self::assertSame(['default' => ['schema' => null], 'dwh' => ['schema' => 'analytics']], $config['components']['postgresql']['connections']);
    }

    public function testAConnectionSchemaCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['components' => ['postgresql' => ['connections' => ['dwh' => ['schema' => '']]]]]);
    }

    public function testTheDeprecatedConnectionOptionBecomesAConnectionsEntry(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $config = $this->process(['components' => ['mysql' => ['connection' => 'reporting']]]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $deprecations);
        self::assertStringContainsString('Since jmonitor/easyadmin-bundle 1.1: The "connection" option of the mysql component is deprecated, use "connections" instead.', $deprecations[0]);
        self::assertSame(['reporting' => []], $config['components']['mysql']['connections']);
        self::assertArrayNotHasKey('connection', $config['components']['mysql']);
    }

    public function testPostgresqlHasNoConnectionOption(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "connection"');

        $this->process(['components' => ['postgresql' => ['connection' => 'dwh']]]);
    }

    public function testTheDeprecatedConnectionOptionStaysInTheTreeForTheConfigBuilders(): void
    {
        $configuration = (new JmonitorEasyAdminBundle())->getContainerExtension()->getConfiguration([], new ContainerBuilder());
        $components = $configuration->getConfigTreeBuilder()->buildTree()->getChildren()['components']->getChildren();

        foreach ([['mysql', 'connection'], ['redis', 'dsn'], ['redis', 'service']] as [$component, $option]) {
            $node = $components[$component]->getChildren()[$option] ?? null;

            self::assertNotNull($node, $component . '.' . $option);
            self::assertTrue($node->isDeprecated(), $component . '.' . $option);
            self::assertFalse($node->hasDefaultValue(), 'without a default the key does not reach the processed configuration');
        }
    }

    public function testConnectionAndConnectionsAreExclusive(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You cannot set both "connection" and "connections"');

        $this->process(['components' => ['mysql' => ['connection' => 'a', 'connections' => ['b']]]]);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, string}>
     */
    public static function provideDeprecatedOptionsAndConnectionsInSeparateFiles(): iterable
    {
        yield 'connection then connections' => [[['mysql' => ['connection' => 'a']], ['mysql' => ['connections' => ['b']]]], 'You cannot set both "connection" and "connections" for the mysql component.'];
        yield 'connections then connection' => [[['mysql' => ['connections' => ['b']]], ['mysql' => ['connection' => 'a']]], 'You cannot set both "connection" and "connections" for the mysql component.'];
        yield 'redis dsn then connections' => [[['redis' => ['dsn' => 'redis://a']], ['redis' => ['connections' => ['cache' => 'redis://b']]]], 'You cannot set both "dsn" and "connections" for the redis component.'];
        yield 'redis connections then service' => [[['redis' => ['connections' => ['cache' => 'redis://b']]], ['redis' => ['service' => 'app.redis']]], 'You cannot set both "service" and "connections" for the redis component.'];
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    #[DataProvider('provideDeprecatedOptionsAndConnectionsInSeparateFiles')]
    public function testTheDeprecatedOptionsAndConnectionsAreExclusiveAcrossFiles(array $files, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process(...array_map(static fn(array $components): array => ['components' => $components], $files));
    }

    public function testADeprecatedOptionGivesTheSameEntryAsConnections(): void
    {
        $deprecated = $this->process(['components' => ['mysql' => ['connection' => 'a'], 'redis' => ['service' => 'app.redis']]]);
        $connections = $this->process(['components' => ['mysql' => ['connections' => ['a']], 'redis' => ['connections' => ['default' => ['service' => 'app.redis']]]]]);

        foreach (['mysql', 'redis'] as $component) {
            self::assertSame($connections['components'][$component]['connections'], $deprecated['components'][$component]['connections'], 'the entry is built by hand, without the defaults of the prototype: ' . $component);
        }
    }

    public function testALaterFileOverridesTheDeprecatedOptions(): void
    {
        $config = $this->process(
            ['components' => ['mysql' => ['connection' => 'a'], 'redis' => ['dsn' => 'redis://a']]],
            ['components' => ['mysql' => ['connection' => 'b'], 'redis' => ['dsn' => 'redis://b']]],
        );

        self::assertSame(['b' => []], $config['components']['mysql']['connections']);
        self::assertSame(['default' => ['dsn' => 'redis://b', 'service' => null]], $config['components']['redis']['connections']);
    }

    public function testMysqlConnectionsTakeNoOption(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['components' => ['mysql' => ['connections' => ['default' => ['schema' => 'x']]]]]);
    }

    public function testPostgresqlSchemaCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['components' => ['postgresql' => ['schema' => '']]]);
    }

    public function testRedisConnectionsAcceptAListOfNamedEntries(): void
    {
        $config = $this->process(['components' => ['redis' => ['connections' => [['name' => 'cache', 'dsn' => 'redis://cache'], ['name' => 'queue', 'service' => 'app.redis_queue']]]]]);

        self::assertEquals(['cache' => ['dsn' => 'redis://cache', 'service' => null], 'queue' => ['dsn' => null, 'service' => 'app.redis_queue']], $config['components']['redis']['connections']);
    }

    public function testASingleXmlRedisConnectionIsOneEntry(): void
    {
        $config = $this->process(['components' => ['redis' => ['connections' => ['name' => 'cache', 'dsn' => 'redis://cache']]]]);

        self::assertSame(['cache' => ['dsn' => 'redis://cache', 'service' => null]], $config['components']['redis']['connections']);
    }

    public function testRedisConnectionsKeepANumericId(): void
    {
        $config = $this->process(['components' => ['redis' => ['connections' => [1 => 'redis://a']]]]);

        self::assertSame([1 => ['dsn' => 'redis://a', 'service' => null]], $config['components']['redis']['connections']);
    }

    public function testRedisIdsNamedNameAndDsnStayTwoConnections(): void
    {
        $config = $this->process(['components' => ['redis' => ['connections' => ['name' => 'redis://a', 'dsn' => 'redis://b']]]]);

        self::assertSame(['name' => ['dsn' => 'redis://a', 'service' => null], 'dsn' => ['dsn' => 'redis://b', 'service' => null]], $config['components']['redis']['connections']);
    }

    public function testRedisConnectionsRefuseAListOfDsns(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Redis connections need an id: use a map id => DSN');

        $this->process(['components' => ['redis' => ['connections' => ['redis://a']]]]);
    }

    public function testARedisConnectionNeedsADsnOrAService(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The Redis connection "cache" needs a "dsn" or a "service".');

        $this->process(['components' => ['redis' => ['connections' => ['cache' => []]]]]);
    }

    public function testARedisConnectionCannotHaveBothADsnAndAService(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The Redis connection "cache" cannot have both a "dsn" and a "service".');

        $this->process(['components' => ['redis' => ['connections' => ['cache' => ['dsn' => 'redis://a', 'service' => 'app.redis']]]]]);
    }

    public function testRedisDsnAndServiceAreExclusive(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You cannot set both "dsn" and "service" for the redis component');

        $this->process(['components' => ['redis' => ['dsn' => 'redis://cache', 'service' => 'app.redis']]]);
    }

    public function testTheDeprecatedRedisDsnAndServiceBecomeTheDefaultConnection(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $dsn = $this->process(['components' => ['redis' => ['dsn' => 'redis://cache']]]);
            $service = $this->process(['components' => ['redis' => ['service' => 'app.redis']]]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(2, $deprecations);
        self::assertStringContainsString('Since jmonitor/easyadmin-bundle 1.1: The "dsn" option of the redis component is deprecated, use "connections" instead.', $deprecations[0]);
        self::assertStringContainsString('Since jmonitor/easyadmin-bundle 1.1: The "service" option of the redis component is deprecated, use "connections" instead.', $deprecations[1]);
        self::assertSame(['default' => ['dsn' => 'redis://cache', 'service' => null]], $dsn['components']['redis']['connections']);
        self::assertEquals(['default' => ['dsn' => null, 'service' => 'app.redis']], $service['components']['redis']['connections']);
        self::assertArrayNotHasKey('dsn', $dsn['components']['redis']);
        self::assertArrayNotHasKey('service', $dsn['components']['redis']);
    }

    public function testANullDeprecatedRedisOptionIsIgnored(): void
    {
        $config = $this->process(['components' => ['redis' => ['dsn' => null, 'service' => null]]]);

        self::assertSame([], $config['components']['redis']['connections']);
    }

    public function testTheDeprecatedRedisOptionsAndConnectionsAreExclusive(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You cannot set both "dsn" and "connections" for the redis component.');

        $this->process(['components' => ['redis' => ['dsn' => 'redis://a', 'connections' => ['cache' => 'redis://b']]]]);
    }

    /**
     * @param array<string, mixed> ...$configs one per configuration file
     */
    private function process(array ...$configs): array
    {
        $configuration = (new JmonitorEasyAdminBundle())->getContainerExtension()->getConfiguration([], new ContainerBuilder());

        return (new Processor())->processConfiguration($configuration, $configs);
    }
}
