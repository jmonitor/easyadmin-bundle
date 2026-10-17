<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Detection\RedisDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RedisDetectorTest extends TestCase
{
    public function testNothingConfiguredIsUnavailable(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), [], null, null))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('REDIS_URL', (string) $detection->error);
        self::assertStringContainsString('components.redis.connections', (string) $detection->error);
    }

    public function testADsnIsEnoughAndDoesNotConnectAtDetection(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), ['default' => ['dsn' => 'redis://127.0.0.1:1', 'service' => null]], null, null))->detect();

        self::assertTrue($detection->isFound());
        self::assertSame('default', $detection->instances[0]->id);
        $spec = $detection->instances[0]->collectors?->get('redis');
        self::assertNotNull($spec);
        self::assertInstanceOf(RedisCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
    }

    public function testAnUnreachableServerFailsAtCollectTimeWithAMessage(): void
    {
        $spec = (new RedisDetector(new ServiceLocator([]), ['default' => ['dsn' => 'redis://127.0.0.1:1', 'service' => null]], null, null))->detect()->instances[0]->collectors?->get('redis');

        try {
            (new CollectorRunner())->run($spec->collector);
            self::fail('Collecting from a closed port must fail');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testEachConfiguredConnectionIsAnInstanceInConfigurationOrder(): void
    {
        $client = new \stdClass();
        $detector = new RedisDetector(new ServiceLocator(['app.redis_queue' => static fn(): object => $client]), [
            'cache' => ['dsn' => 'redis://127.0.0.1:1', 'service' => null],
            'queue' => ['dsn' => null, 'service' => 'app.redis_queue'],
        ], 'redis://ignored', 'redis://ignored');

        $instances = $detector->detect()->instances;

        self::assertSame(['cache', 'queue'], array_map(static fn(Instance $instance): ?string => $instance->id, $instances));
        self::assertSame('127.0.0.1:1', $instances[0]->detail);
        self::assertNull($instances[1]->detail);
        self::assertTrue($instances[0]->isFound());
        self::assertTrue($instances[1]->isFound());
        self::assertSame('redis.cache.', $instances[0]->keyPrefix());
    }

    public function testAnUnknownConfiguredServiceIsAnInstanceInError(): void
    {
        $instance = (new RedisDetector(new ServiceLocator([]), ['queue' => ['dsn' => null, 'service' => 'app.nope']], null, null))->detect()->instances[0];

        self::assertFalse($instance->isFound());
        self::assertSame('queue', $instance->id);
        self::assertSame('Unknown Redis service "app.nope".', $instance->error);
    }

    public function testAServiceThatThrowsIsAnInstanceInError(): void
    {
        $locator = new ServiceLocator(['app.redis' => static fn(): object => throw new \RuntimeException('boom')]);
        $instance = (new RedisDetector($locator, ['queue' => ['dsn' => null, 'service' => 'app.redis']], null, null))->detect()->instances[0];

        self::assertFalse($instance->isFound());
        self::assertSame('boom', $instance->error);
    }

    public function testAnInvalidConfiguredDsnIsAnInstanceInError(): void
    {
        $instance = (new RedisDetector(new ServiceLocator([]), ['cache' => ['dsn' => 'not a dsn', 'service' => null]], null, null))->detect()->instances[0];

        self::assertFalse($instance->isFound());
        self::assertStringStartsWith('Invalid Redis DSN:', (string) $instance->error);
        self::assertNull($instance->detail);
    }

    public function testAnEmptyConfiguredDsnIsAnInstanceInError(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), ['default' => ['dsn' => '', 'service' => null]], null, 'redis://127.0.0.1:1'))->detect();

        self::assertCount(1, $detection->instances);
        self::assertSame('Empty Redis DSN.', $detection->instances[0]->error);
    }

    public function testANumericIdBecomesAString(): void
    {
        self::assertSame('1', (new RedisDetector(new ServiceLocator([]), [1 => ['dsn' => 'redis://127.0.0.1:1', 'service' => null]], null, null))->detect()->instances[0]->id);
    }

    #[DataProvider('provideDetails')]
    public function testTheDetailIsTheServerWithoutCredentials(string $dsn, ?string $detail): void
    {
        self::assertSame($detail, RedisDetector::detail($dsn));
    }

    public static function provideDetails(): iterable
    {
        yield 'host and port' => ['redis://localhost:6379', 'localhost:6379'];
        yield 'host only' => ['redis://cache', 'cache'];
        yield 'credentials and database' => ['redis://user:secret@sessions:6380/2?timeout=1', 'sessions:6380/2'];
        yield 'tls' => ['rediss://cache:6379/0', 'cache:6379/0'];
        yield 'unparsable' => ['not a dsn', null];
        yield 'no host' => ['redis:///var/run/redis.sock', null];
    }

    public function testTheAutomaticDetectionStaysImplicit(): void
    {
        $instance = (new RedisDetector(new ServiceLocator([]), [], null, 'redis://127.0.0.1:1'))->detect()->instances[0];

        self::assertNull($instance->id);
        self::assertNull($instance->detail);
        self::assertSame('', $instance->keyPrefix());
    }

    public function testTheFrameworkProviderBeatsTheEnvVar(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), [], 'not a dsn', 'redis://127.0.0.1:1'))->detect();

        self::assertFalse($detection->isFound(), 'the provider was tried first and is invalid');
    }

    public function testAFrameworkProviderThatIsAServiceIdIsResolvedFromTheLocator(): void
    {
        $client = new \stdClass();
        $detector = new RedisDetector(new ServiceLocator(['app.redis' => static fn(): object => $client]), [], 'app.redis', null);

        self::assertTrue($detector->detect()->isFound());
    }

    public function testAFrameworkProviderServiceIdThatIsMissingIsUnavailable(): void
    {
        $detection = (new RedisDetector(new ServiceLocator([]), [], 'app.redis', 'redis://127.0.0.1:1'))->detect();

        self::assertFalse($detection->isFound());
        self::assertStringContainsString('app.redis', (string) $detection->error);
    }

    public function testTheEnvVarIsTheLastResort(): void
    {
        self::assertTrue((new RedisDetector(new ServiceLocator([]), [], null, 'redis://127.0.0.1:1'))->detect()->isFound());
        self::assertFalse((new RedisDetector(new ServiceLocator([]), [], null, ''))->detect()->isFound(), 'an empty env var counts as unset');
    }
}
