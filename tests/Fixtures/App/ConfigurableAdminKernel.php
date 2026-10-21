<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\App;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ApacheDetector;
use Jmonitor\EasyAdminBundle\Detection\FrankenPhpDetector;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Detection\NginxDetector;
use Jmonitor\EasyAdminBundle\Logging\MinimumLevelLogger;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\InstancesDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\ThrowingDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Redis\FakeRedisClient;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * WebTestCase instantiates the kernel itself, so the configuration is set on static properties
 * before creating the client.
 */
final class ConfigurableAdminKernel extends TestKernel
{
    /** The Symfony component without its sub-collectors, which run console or composer processes. */
    public const QUIET_SYMFONY = ['enabled' => 'auto', 'messenger' => false, 'scheduler' => false, 'flex' => false];

    /** @var array<string, mixed> */
    public static array $bundleConfiguration = [];
    public static ?string $mysqlDetectorFailure = null;
    /** @var non-empty-list<array{id: string, detail?: ?string, error?: string}>|null */
    public static ?array $mysqlInstances = null;
    /** @var list<string> ids registered as FakeRedisClient services */
    public static array $redisServices = [];
    /** @var array{sapi: string, metrics: ?string, version: ?string}|null the SAPI, the Caddy export and the "frankenphp version" output */
    public static ?array $frankenphp = null;
    /** @var array{sapi: string, status: ?string}|null the SAPI and the mod_status report */
    public static ?array $apache = null;
    /** @var array{status: ?string, commands: array<string, ?string>}|null the stub_status page and the outputs of the commands */
    public static ?array $nginx = null;

    private readonly ?string $detectorFailure;
    /** @var list<string> */
    private readonly array $fakeRedisServices;
    /** @var non-empty-list<array{id: string, detail?: ?string, error?: string}>|null */
    private readonly ?array $instances;
    /** @var array{sapi: string, metrics: ?string, version: ?string}|null */
    private readonly ?array $frankenphpFixtures;
    /** @var array{sapi: string, status: ?string}|null */
    private readonly ?array $apacheFixtures;
    /** @var array{status: ?string, commands: array<string, ?string>}|null */
    private readonly ?array $nginxFixtures;

    public function __construct(string $environment = 'test', bool $debug = true)
    {
        parent::__construct(self::$bundleConfiguration);
        $this->detectorFailure = self::$mysqlDetectorFailure;
        $this->instances = self::$mysqlInstances;
        $this->fakeRedisServices = self::$redisServices;
        $this->frankenphpFixtures = self::$frankenphp;
        $this->apacheFixtures = self::$apache;
        $this->nginxFixtures = self::$nginx;
    }

    public static function reset(): void
    {
        self::$bundleConfiguration = [];
        self::$mysqlDetectorFailure = null;
        self::$mysqlInstances = null;
        self::$redisServices = [];
        self::$frankenphp = null;
        self::$apache = null;
        self::$nginx = null;
    }

    public function getCacheDir(): string
    {
        $replacement = $this->mysqlDetector();

        if ($replacement === null && $this->fakeRedisServices === [] && $this->frankenphpFixtures === null && $this->apacheFixtures === null && $this->nginxFixtures === null) {
            return parent::getCacheDir();
        }

        return parent::getCacheDir() . '-' . md5(serialize([$replacement?->getArguments(), $this->fakeRedisServices, $this->frankenphpFixtures, $this->apacheFixtures, $this->nginxFixtures]));
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if ($this->fakeRedisServices !== []) {
            // Before the optimization passes, so that the ignoreOnInvalid references of the Redis locator see them.
            $container->addCompilerPass(new class ($this->fakeRedisServices) implements CompilerPassInterface {
                /** @param list<string> $ids */
                public function __construct(private readonly array $ids) {}

                public function process(ContainerBuilder $container): void
                {
                    foreach ($this->ids as $id) {
                        $container->setDefinition($id, new Definition(FakeRedisClient::class));
                    }
                }
            }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }

        if ($this->frankenphpFixtures !== null) {
            $container->addCompilerPass(new class ($this->frankenphpFixtures) implements CompilerPassInterface {
                /** @param array{sapi: string, metrics: ?string, version: ?string} $fixtures */
                public function __construct(private readonly array $fixtures) {}

                public function process(ContainerBuilder $container): void
                {
                    $definition = new Definition(FrankenPhpDetector::class, [
                        'http://localhost:2019/metrics',
                        new Reference('cache.app'),
                        3600,
                        new Reference(MinimumLevelLogger::class),
                        $this->fixtures['sapi'],
                        new Definition(FixtureUrlFetcher::class, [$this->fixtures['metrics']]),
                        new Definition(FixtureShellExecutor::class, [$this->fixtures['version']]),
                    ]);
                    $container->setDefinition(FrankenPhpDetector::class, $definition->addTag('jmonitor_easyadmin.detector', ['index' => Component::FrankenPhp->value]));
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        }

        if ($this->apacheFixtures !== null) {
            $container->addCompilerPass(new class ($this->apacheFixtures) implements CompilerPassInterface {
                /** @param array{sapi: string, status: ?string} $fixtures */
                public function __construct(private readonly array $fixtures) {}

                public function process(ContainerBuilder $container): void
                {
                    $definition = new Definition(ApacheDetector::class, [
                        'http://localhost/server-status',
                        new Reference('request_stack'),
                        $this->fixtures['sapi'],
                        new Definition(FixtureUrlFetcher::class, [$this->fixtures['status']]),
                    ]);
                    $container->setDefinition(ApacheDetector::class, $definition->addTag('jmonitor_easyadmin.detector', ['index' => Component::Apache->value]));
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        }

        if ($this->nginxFixtures !== null) {
            $container->addCompilerPass(new class ($this->nginxFixtures) implements CompilerPassInterface {
                /** @param array{status: ?string, commands: array<string, ?string>} $fixtures */
                public function __construct(private readonly array $fixtures) {}

                public function process(ContainerBuilder $container): void
                {
                    $definition = new Definition(NginxDetector::class, [
                        'http://localhost/nginx_status',
                        new Reference('request_stack'),
                        new Reference('cache.app'),
                        3600,
                        new Reference(MinimumLevelLogger::class),
                        new Definition(FixtureUrlFetcher::class, [$this->fixtures['status']]),
                        new Definition(FixtureShellExecutor::class, [$this->fixtures['commands']]),
                    ]);
                    $container->setDefinition(NginxDetector::class, $definition->addTag('jmonitor_easyadmin.detector', ['index' => Component::Nginx->value]));
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        }

        $replacement = $this->mysqlDetector();

        if ($replacement === null) {
            return;
        }

        $container->addCompilerPass(new class ($replacement) implements CompilerPassInterface {
            public function __construct(private readonly Definition $definition) {}

            public function process(ContainerBuilder $container): void
            {
                $container->setDefinition(MysqlDetector::class, $this->definition->addTag('jmonitor_easyadmin.detector', ['index' => Component::Mysql->value]));
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    private function mysqlDetector(): ?Definition
    {
        if ($this->detectorFailure !== null) {
            return new Definition(ThrowingDetector::class, [Component::Mysql, $this->detectorFailure]);
        }

        if ($this->instances !== null) {
            return new Definition(InstancesDetector::class, [Component::Mysql, $this->instances]);
        }

        return null;
    }
}
