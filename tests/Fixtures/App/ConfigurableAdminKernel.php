<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\App;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\InstancesDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\ThrowingDetector;
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

    private readonly ?string $detectorFailure;
    /** @var non-empty-list<array{id: string, detail?: ?string, error?: string}>|null */
    private readonly ?array $instances;

    public function __construct(string $environment = 'test', bool $debug = true)
    {
        parent::__construct(self::$bundleConfiguration);
        $this->detectorFailure = self::$mysqlDetectorFailure;
        $this->instances = self::$mysqlInstances;
    }

    public static function reset(): void
    {
        self::$bundleConfiguration = [];
        self::$mysqlDetectorFailure = null;
        self::$mysqlInstances = null;
    }

    public function getCacheDir(): string
    {
        $replacement = $this->mysqlDetector();

        return parent::getCacheDir() . ($replacement === null ? '' : '-' . md5(serialize($replacement->getArguments())));
    }

    protected function build(ContainerBuilder $container): void
    {
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
