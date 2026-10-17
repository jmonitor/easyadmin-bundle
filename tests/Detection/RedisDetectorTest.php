<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Detection\RedisDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RedisDetectorTest extends TestCase
{
    public function testNothingConfiguredIsUnavailable(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), null, null, null, null))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('REDIS_URL', (string) $detection->error);
    }

    public function testADsnIsEnoughAndDoesNotConnectAtDetection(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), null, 'redis://127.0.0.1:1', null, null))->detect();

        self::assertTrue($detection->isFound());
        $spec = $detection->instances[0]->collectors?->get('redis');
        self::assertNotNull($spec);
        self::assertInstanceOf(RedisCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
    }

    public function testAnUnreachableServerFailsAtCollectTimeWithAMessage(): void
    {
        $spec = (new RedisDetector(new ServiceLocator([]), null, 'redis://127.0.0.1:1', null, null))->detect()->instances[0]->collectors?->get('redis');

        try {
            (new CollectorRunner())->run($spec->collector);
            self::fail('Collecting from a closed port must fail');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testAnInvalidDsnIsUnavailable(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), null, 'not a dsn', null, null))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('Redis DSN', (string) $detection->error);
    }

    public function testAConfiguredServiceWins(): void
    {
        $client = new \stdClass();
        $detector = new RedisDetector(new ServiceLocator(['app.redis' => static fn(): object => $client]), 'app.redis', null, 'not a dsn', 'not a dsn');

        self::assertTrue($detector->detect()->isFound());
    }

    public function testAnUnknownServiceIsUnavailable(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), 'app.redis', null, null, null))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('app.redis', (string) $detection->error);
    }

    public function testTheFrameworkProviderBeatsTheEnvVar(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), null, null, 'not a dsn', 'redis://127.0.0.1:1'))->detect();

        self::assertFalse($detection->isFound(), 'the provider was tried first and is invalid');
    }

    public function testAFrameworkProviderThatIsAServiceIdIsResolvedFromTheLocator(): void
    {
        $client = new \stdClass();
        $detector = new RedisDetector(new ServiceLocator(['app.redis' => static fn(): object => $client]), null, null, 'app.redis', null);

        self::assertTrue($detector->detect()->isFound());
    }

    public function testAFrameworkProviderServiceIdThatIsMissingIsUnavailable(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), null, null, 'app.redis', 'redis://127.0.0.1:1'))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('app.redis', (string) $detection->error);
    }

    public function testTheEnvVarIsTheLastResort(): void
    {
        self::assertTrue((new RedisDetector(new ServiceLocator([]), null, null, null, 'redis://127.0.0.1:1'))->detect()->isFound());
        self::assertFalse((new RedisDetector(new ServiceLocator([]), null, null, null, ''))->detect()->isFound(), 'an empty env var counts as unset');
    }
}
