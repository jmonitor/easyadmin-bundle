<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\App;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\MysqlDetector;
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

    private readonly ?string $detectorFailure;

    public function __construct(string $environment = 'test', bool $debug = true)
    {
        parent::__construct(self::$bundleConfiguration);
        $this->detectorFailure = self::$mysqlDetectorFailure;
    }

    public static function reset(): void
    {
        self::$bundleConfiguration = [];
        self::$mysqlDetectorFailure = null;
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . ($this->detectorFailure === null ? '' : '-' . md5($this->detectorFailure));
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->detectorFailure === null) {
            return;
        }

        $container->addCompilerPass(new class ($this->detectorFailure) implements CompilerPassInterface {
            public function __construct(private readonly string $message) {}

            public function process(ContainerBuilder $container): void
            {
                $container->setDefinition(MysqlDetector::class, (new Definition(ThrowingDetector::class, [Component::Mysql, $this->message]))
                    ->addTag('jmonitor_easyadmin.detector', ['index' => Component::Mysql->value]));
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }
}
