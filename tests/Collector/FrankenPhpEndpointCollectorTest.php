<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\Collector\FrankenPhp\FrankenPhpCollector;
use Jmonitor\EasyAdminBundle\Collector\CachedShellExecutor;
use Jmonitor\EasyAdminBundle\Collector\FrankenPhpEndpointCollector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FrankenPhpEndpointCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost:2019/metrics';

    public function testClassicMode(): void
    {
        $collector = $this->collector(self::export('classic'));

        self::assertSame([
            'version' => '1.4.0',
            'mode' => 'classic',
            'busy_threads' => 3,
            'total_threads' => 16,
            'queue_depth' => 0,
            'workers' => [],
        ], $collector->collect());
    }

    public function testWorkerMode(): void
    {
        $payload = $this->collector(self::export('worker'))->collect();

        self::assertSame('worker', $payload['mode']);
        self::assertSame(16, $payload['total_threads']);
        self::assertSame(13, $payload['busy_threads'], 'the 12 worker threads count as busy, plus one regular request');
        self::assertCount(2, $payload['workers']);
        self::assertSame([
            'name' => '/app/public/index.php',
            'total_workers' => 8,
            'busy_workers' => 2,
            'worker_request_time' => 125.5,
            'worker_request_count' => 10000,
            'ready_workers' => 8,
            'worker_crashes' => 1,
            'worker_restarts' => 3,
            'worker_queue_depth' => 0,
        ], $payload['workers'][0]);
        self::assertSame('api', $payload['workers'][1]['name']);
        self::assertSame(4, $payload['workers'][1]['busy_workers']);
        self::assertSame(2, $payload['workers'][1]['worker_queue_depth']);
    }

    public function testAnExportWithoutFrankenPhpMetricsIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No FrankenPHP metrics at http://localhost:2019/metrics. Enable them with the "metrics" global option of the Caddyfile.');

        $this->collector(self::export('caddy-only'))->collect();
    }

    public function testAnUnreachableEndpointIsAnError(): void
    {
        $shell = new FixtureShellExecutor("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n");
        $collector = $this->collector(null, $shell);

        try {
            $collector->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Cannot read the FrankenPHP metrics at http://localhost:2019/metrics. Enable them with the "metrics" global option of the Caddyfile and check "components.frankenphp.endpoint".', $e->getMessage());
            self::assertStringContainsString('Connection refused', (string) $e->getPrevious()?->getMessage(), 'the cause is chained for SnapshotProvider::describe()');
        }

        self::assertSame(0, $shell->calls, 'no version lookup when the metrics cannot be read');
    }

    public function testNameAndVersionDelegate(): void
    {
        $collector = $this->collector(self::export('classic'));

        self::assertSame('frankenphp', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testRefreshForgetsTheCachedVersion(): void
    {
        $cache = new ArrayAdapter();
        $shell = new FixtureShellExecutor(null);
        $cached = new CachedShellExecutor($cache, 3600, 'frankenphp', inner: $shell);
        $inner = new FrankenPhpCollector(new PrometheusMetricsProvider(self::ENDPOINT, new FixtureUrlFetcher(self::export('classic'))), $cached);
        $collector = new FrankenPhpEndpointCollector($inner, self::ENDPOINT, $cached);

        self::assertNull($collector->collect()['version']);
        self::assertTrue($cache->hasItem('jmonitor_easyadmin.shell.frankenphp'), 'the empty version is cached');

        $collector->refresh();

        self::assertFalse($cache->hasItem('jmonitor_easyadmin.shell.frankenphp'));
    }

    public static function export(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/FrankenPhp/metrics-' . $name . '.prom');
    }

    private function collector(?string $export, ?FixtureShellExecutor $shell = null): FrankenPhpEndpointCollector
    {
        $cached = new CachedShellExecutor(new ArrayAdapter(), 3600, 'frankenphp', inner: $shell ?? new FixtureShellExecutor("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n"));
        $inner = new FrankenPhpCollector(new PrometheusMetricsProvider(self::ENDPOINT, new FixtureUrlFetcher($export)), $cached);

        return new FrankenPhpEndpointCollector($inner, self::ENDPOINT, $cached);
    }
}
