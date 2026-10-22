<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\FrankenPhpEndpointCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\RefreshableCollectorInterface;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\FrankenPhpDetector;
use Jmonitor\EasyAdminBundle\Tests\Collector\FrankenPhpEndpointCollectorTest;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FrankenPhpDetectorTest extends TestCase
{
    public function testFrankenPhpIsDetectedFromTheSapiWithoutReadingTheEndpoint(): void
    {
        $fetcher = new FixtureUrlFetcher(null);
        $shell = new FixtureShellExecutor(null);

        $detection = (new FrankenPhpDetector('http://localhost:2019/metrics', new ArrayAdapter(), 3600, new NullLogger(), 'frankenphp', $fetcher, $shell))->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(Component::FrankenPhp, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id, 'implicit instance');
        self::assertSame(['frankenphp'], $detection->instances[0]->collectors?->names());
        $spec = $detection->instances[0]->collectors->get('frankenphp');
        self::assertNotNull($spec);
        self::assertInstanceOf(FrankenPhpEndpointCollector::class, $spec->collector);
        self::assertInstanceOf(RefreshableCollectorInterface::class, $spec->collector, 'Refresh drops the cached version');
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
        self::assertSame(0, $fetcher->calls, 'detection never touches the network');
        self::assertSame(0, $shell->calls, 'detection never starts a process');
    }

    public function testAnotherSapiIsUnavailable(): void
    {
        $detection = (new FrankenPhpDetector('http://localhost:2019/metrics', new ArrayAdapter(), 3600, new NullLogger(), 'fpm-fcgi'))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('FrankenPHP is not serving this page.', $detection->error);
    }

    public function testTheCollectorReadsTheConfiguredEndpoint(): void
    {
        $fetcher = new FixtureUrlFetcher(FrankenPhpEndpointCollectorTest::export('worker'));
        $cache = new ArrayAdapter();
        $detection = (new FrankenPhpDetector('http://caddy:2019/metrics', $cache, 3600, new NullLogger(), 'frankenphp', $fetcher, new FixtureShellExecutor("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n")))->detect();

        $payload = $detection->instances[0]->collectors?->get('frankenphp')?->collector->collect();

        self::assertSame('worker', $payload['mode'] ?? null);
        self::assertSame('1.4.0', $payload['version'] ?? null);
        self::assertSame(1, $fetcher->calls);
        self::assertTrue($cache->hasItem('jmonitor_easyadmin.shell.frankenphp'), 'the version is cached for static_ttl');
    }
}
