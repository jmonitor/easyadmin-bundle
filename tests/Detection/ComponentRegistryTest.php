<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Detection;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\StaticDetector;
use PHPUnit\Framework\TestCase;

final class ComponentRegistryTest extends TestCase
{
    public function testAutoKeepsFoundComponentsInEnumOrder(): void
    {
        $registry = new ComponentRegistry([
            'redis' => new StaticDetector(Component::Redis, $this->found(Component::Redis)),
            'system' => new StaticDetector(Component::System, $this->found(Component::System)),
            'mysql' => new StaticDetector(Component::Mysql, Detection::unavailable('No MySQL connection')),
        ], $this->config());

        self::assertSame([Component::System, Component::Redis], $registry->available());
        self::assertTrue($registry->isAvailable(Component::Redis));
        self::assertFalse($registry->isAvailable(Component::Mysql));
        self::assertSame([], $registry->instancesFor(Component::Mysql));
        self::assertNull($registry->instance(Component::Mysql, null), 'an auto component that is not found is simply absent');
    }

    public function testForcedComponentStaysListedWithItsError(): void
    {
        $registry = new ComponentRegistry([
            'mysql' => new StaticDetector(Component::Mysql, Detection::unavailable('Connection refused')),
        ], $this->config(['mysql' => ['enabled' => true]]));

        self::assertSame([Component::Mysql], $registry->available());
        $instance = $registry->instance(Component::Mysql, null);
        self::assertNotNull($instance);
        self::assertFalse($instance->isFound());
        self::assertNull($instance->id);
        self::assertSame('Connection refused', $instance->error);
        self::assertSame([$instance], $registry->instancesFor(Component::Mysql));
    }

    public function testDisabledComponentIsNeverDetected(): void
    {
        $detector = new StaticDetector(Component::Redis, $this->found(Component::Redis));
        $registry = new ComponentRegistry(['redis' => $detector], $this->config(['redis' => ['enabled' => false]]));

        self::assertSame([], $registry->available());
        self::assertSame(0, $detector->calls);
    }

    public function testDetectionRunsOnceUntilReset(): void
    {
        $detector = new StaticDetector(Component::Php, $this->found(Component::Php));
        $registry = new ComponentRegistry(['php' => $detector], $this->config());

        $registry->available();
        $registry->instancesFor(Component::Php);
        self::assertSame(1, $detector->calls);

        $registry->reset();
        $registry->available();
        self::assertSame(2, $detector->calls);
    }

    public function testAComponentIsDetectedWhenAskedFor(): void
    {
        $mysql = new StaticDetector(Component::Mysql, $this->found(Component::Mysql));
        $redis = new StaticDetector(Component::Redis, $this->found(Component::Redis));
        $registry = new ComponentRegistry(['mysql' => $mysql, 'redis' => $redis], $this->config());

        self::assertNotNull($registry->instance(Component::Redis, null));
        self::assertTrue($registry->isAvailable(Component::Redis));
        self::assertSame(0, $mysql->calls, 'the other components are left alone');

        self::assertSame([Component::Mysql, Component::Redis], $registry->available());
        self::assertSame(1, $mysql->calls);
        self::assertSame(1, $redis->calls);
    }

    public function testTheImplicitInstanceCarriesTheDetectedSet(): void
    {
        $set = new CollectorSet(Component::Php, [new CollectorSpec(new FakeCollector('php'), Lifetime::Dynamic)]);
        $registry = new ComponentRegistry(['php' => new StaticDetector(Component::Php, Detection::found($set))], $this->config());

        self::assertSame($set, $registry->instance(Component::Php, null)?->collectors);
        self::assertNull($registry->instance(Component::Php, 'anything'), 'an implicit instance has no id');
    }

    public function testANamedInstanceIsFoundByItsIdAndTheFirstOneByDefault(): void
    {
        $default = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'default', 'shop');
        $legacy = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), 'legacy', 'erp');
        $registry = new ComponentRegistry(['mysql' => new StaticDetector(Component::Mysql, Detection::instances([$default, $legacy]))], $this->config());

        self::assertSame([$default, $legacy], $registry->instancesFor(Component::Mysql));
        self::assertSame($default, $registry->instance(Component::Mysql, null));
        self::assertSame($legacy, $registry->instance(Component::Mysql, 'legacy'));
        self::assertNull($registry->instance(Component::Mysql, 'nope'));
    }

    public function testAThrowingDetectorBecomesAnUnavailableComponent(): void
    {
        $throwing = new class implements \Jmonitor\EasyAdminBundle\Detection\ComponentDetectorInterface {
            public function component(): Component
            {
                return Component::Mysql;
            }

            public function detect(): Detection
            {
                throw new \RuntimeException('Malformed DSN');
            }
        };

        $auto = new ComponentRegistry(['mysql' => $throwing, 'php' => new StaticDetector(Component::Php, $this->found(Component::Php))], $this->config());
        self::assertSame([Component::Php], $auto->available(), 'auto: the component is simply absent, the other tabs still render');

        $forced = new ComponentRegistry(['mysql' => $throwing], $this->config(['mysql' => ['enabled' => true]]));
        self::assertSame([Component::Mysql], $forced->available());
        self::assertSame('Malformed DSN', $forced->instance(Component::Mysql, null)?->error);
    }

    private function found(Component $component): Detection
    {
        return Detection::found(new CollectorSet($component, [new CollectorSpec(new FakeCollector($component->value), Lifetime::Dynamic)]));
    }

    /**
     * @param array<string, array{enabled: 'auto'|bool}> $overrides
     *
     * @return array<string, array{enabled: 'auto'|bool}>
     */
    private function config(array $overrides = []): array
    {
        $config = [];

        foreach (Component::cases() as $component) {
            $config[$component->value] = ['enabled' => 'auto'];
        }

        return array_replace($config, $overrides);
    }
}
