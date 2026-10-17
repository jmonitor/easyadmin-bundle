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
        self::assertNull($config['components']['redis']['dsn']);
        self::assertNull($config['components']['redis']['service']);
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
            'redis' => ['dsn' => 'redis://cache'],
            'symfony' => ['flex' => false],
        ]]);

        self::assertSame('auto', $config['components']['mysql']['enabled']);
        self::assertSame(['default' => [], 'legacy' => []], $config['components']['mysql']['connections']);
        self::assertSame(['default' => ['schema' => null], 'dwh' => ['schema' => 'analytics']], $config['components']['postgresql']['connections']);
        self::assertSame('app', $config['components']['postgresql']['schema']);
        self::assertSame('redis://cache', $config['components']['redis']['dsn']);
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
            $config = $this->process(['components' => ['mysql' => ['connection' => 'reporting'], 'postgresql' => ['connection' => 'dwh']]]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(2, $deprecations);
        self::assertStringContainsString('Since jmonitor/easyadmin-bundle 1.1: The "connection" option of the mysql component is deprecated, use "connections" instead.', $deprecations[0]);
        self::assertSame(['reporting' => []], $config['components']['mysql']['connections']);
        self::assertSame(['dwh' => ['schema' => null]], $config['components']['postgresql']['connections']);
        self::assertArrayNotHasKey('connection', $config['components']['postgresql']);
    }

    public function testTheDeprecatedConnectionOptionStaysInTheTreeForTheConfigBuilders(): void
    {
        $configuration = (new JmonitorEasyAdminBundle())->getContainerExtension()->getConfiguration([], new ContainerBuilder());
        $components = $configuration->getConfigTreeBuilder()->buildTree()->getChildren()['components']->getChildren();

        foreach (['mysql', 'postgresql'] as $component) {
            $connection = $components[$component]->getChildren()['connection'] ?? null;

            self::assertNotNull($connection, $component);
            self::assertTrue($connection->isDeprecated(), $component);
            self::assertFalse($connection->hasDefaultValue(), 'without a default the key does not reach the processed configuration');
        }
    }

    public function testConnectionAndConnectionsAreExclusive(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You cannot set both "connection" and "connections"');

        $this->process(['components' => ['mysql' => ['connection' => 'a', 'connections' => ['b']]]]);
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

    public function testRedisDsnAndServiceAreExclusive(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You cannot set both "dsn" and "service"');

        $this->process(['components' => ['redis' => ['dsn' => 'redis://cache', 'service' => 'app.redis']]]);
    }

    private function process(array $config): array
    {
        $configuration = (new JmonitorEasyAdminBundle())->getContainerExtension()->getConfiguration([], new ContainerBuilder());

        return (new Processor())->processConfiguration($configuration, ['jmonitor_easyadmin' => $config]);
    }
}
