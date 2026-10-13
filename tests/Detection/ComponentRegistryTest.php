<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Detection;
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
        self::assertNull($registry->collectorsFor(Component::Mysql));
        self::assertNull($registry->errorFor(Component::Mysql), 'an auto component that is not found is simply absent');
    }

    public function testForcedComponentStaysListedWithItsError(): void
    {
        $registry = new ComponentRegistry([
            'mysql' => new StaticDetector(Component::Mysql, Detection::unavailable('Connection refused')),
        ], $this->config(['mysql' => ['enabled' => true]]));

        self::assertSame([Component::Mysql], $registry->available());
        self::assertNull($registry->collectorsFor(Component::Mysql));
        self::assertSame('Connection refused', $registry->errorFor(Component::Mysql));
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
        $registry->collectorsFor(Component::Php);
        self::assertSame(1, $detector->calls);

        $registry->reset();
        $registry->available();
        self::assertSame(2, $detector->calls);
    }

    public function testCollectorsForReturnsTheDetectedSet(): void
    {
        $set = new CollectorSet(Component::Php, [new CollectorSpec(new FakeCollector('php'), Lifetime::Dynamic)]);
        $registry = new ComponentRegistry(['php' => new StaticDetector(Component::Php, Detection::found($set))], $this->config());

        self::assertSame($set, $registry->collectorsFor(Component::Php));
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
        self::assertSame('Malformed DSN', $forced->errorFor(Component::Mysql));
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
