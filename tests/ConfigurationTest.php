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
        self::assertSame('ROLE_ADMIN', $config['role']);
        self::assertSame('https://jmonitor.io/help/metric/', $config['help_url']);

        foreach (['system', 'php', 'symfony', 'mysql', 'redis'] as $component) {
            self::assertSame('auto', $config['components'][$component]['enabled'], $component);
        }

        self::assertNull($config['components']['mysql']['connection']);
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
            'mysql' => ['connection' => 'doctrine.dbal.reporting_connection'],
            'redis' => ['dsn' => 'redis://cache'],
            'symfony' => ['flex' => false],
        ]]);

        self::assertSame('auto', $config['components']['mysql']['enabled']);
        self::assertSame('doctrine.dbal.reporting_connection', $config['components']['mysql']['connection']);
        self::assertSame('redis://cache', $config['components']['redis']['dsn']);
        self::assertFalse($config['components']['symfony']['flex']);
    }

    public function testHelpCanBeDisabled(): void
    {
        self::assertFalse($this->process(['help_url' => false])['help_url']);
    }

    public function testEmptyHelpUrlIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['help_url' => '']);
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
