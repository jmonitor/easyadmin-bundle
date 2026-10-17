<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\App;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\InstancesDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\ThrowingDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Redis\FakeRedisClient;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * WebTestCase instantiates the kernel itself, so the configuration is set on static properties
 * before creating the client.
 */
final class ConfigurableAdminKernel extends TestKernel
{
    /** @var array<string, mixed> */
    public static array $bundleConfiguration = [];
    public static ?string $mysqlDetectorFailure = null;
    /** @var non-empty-list<array{id: string, detail?: ?string, error?: string}>|null */
    public static ?array $mysqlInstances = null;
    /** @var list<string> ids registered as FakeRedisClient services */
    public static array $redisServices = [];

    private readonly ?string $detectorFailure;
    /** @var list<string> */
    private readonly array $fakeRedisServices;
    /** @var non-empty-list<array{id: string, detail?: ?string, error?: string}>|null */
    private readonly ?array $instances;

    public function __construct(string $environment = 'test', bool $debug = true)
    {
        parent::__construct(self::$bundleConfiguration);
        $this->detectorFailure = self::$mysqlDetectorFailure;
        $this->instances = self::$mysqlInstances;
        $this->fakeRedisServices = self::$redisServices;
    }

    public static function reset(): void
    {
        self::$bundleConfiguration = [];
        self::$mysqlDetectorFailure = null;
        self::$mysqlInstances = null;
        self::$redisServices = [];
    }

    public function getCacheDir(): string
    {
        $replacement = $this->mysqlDetector();

        if ($replacement === null && $this->fakeRedisServices === []) {
            return parent::getCacheDir();
        }

        return parent::getCacheDir() . '-' . md5(serialize([$replacement?->getArguments(), $this->fakeRedisServices]));
    }

    protected function build(ContainerBuilder $container): void
    {
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
