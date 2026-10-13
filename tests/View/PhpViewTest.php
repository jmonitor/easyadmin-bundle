<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\View\PhpView;
use PHPUnit\Framework\TestCase;

final class PhpViewTest extends TestCase
{
    public function testFullPayloadUnderFpm(): void
    {
        $previous = ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1000, 'misses' => 100]]]];
        $current = ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1090, 'misses' => 110]]]];
        $delta = new Delta(Delta::flatten($previous), Delta::flatten($current), 10);

        $view = new PhpView($this->snapshot($this->payload(), $delta), $this->gauges());

        self::assertTrue($view->available);
        self::assertSame('8.4.23', $view->version);
        self::assertSame('fpm-fcgi', $view->sapi);
        self::assertSame('/etc/php/8.4/fpm/php.ini', $view->iniFile);
        self::assertSame(['/etc/php/8.4/fpm/conf.d/10-opcache.ini'], $view->iniFiles);
        self::assertSame(['apcu', 'Core', 'json', 'Zend OPcache'], $view->extensions, 'sorted without regard to case');
        self::assertSame('128M', $view->baseConfig['memory_limit']);
        self::assertSame(30, $view->baseConfig['max_execution_time']);
        self::assertFalse($view->baseConfig['display_errors']);

        self::assertTrue($view->opcacheEnabled);
        self::assertSame(60.0, $view->opcacheMemory?->value);
        self::assertSame(BadgeStyle::Success, $view->opcacheMemory->badge?->style);
        self::assertSame(90.0, $view->opcacheHitRate?->value, 'hit rate from the delta: 90 hits for 10 misses');
        self::assertNull($view->opcacheHitRate->hint);
        self::assertSame(134217728, $view->opcacheConfig['opcache.memory_consumption']);
        self::assertSame(1234, $view->opcacheStats['Cached scripts']);

        self::assertTrue($view->apcuEnabled);
        self::assertSame(75.0, $view->apcuMemory?->value);
        self::assertSame(90.0, $view->apcuHitRate?->value);
        self::assertSame('since server start', $view->apcuHitRate->hint, 'no APCu counters in the delta: cumulative value');
        self::assertSame(42, $view->apcuStats['Entries']);

        self::assertTrue($view->fpm);
        self::assertSame(25.0, $view->fpmActive?->value, '2 active out of 2 + 6');
        self::assertSame(50.0, $view->fpmMemoryPeak?->value, '64 MB peak for a 128M memory_limit');
        self::assertSame('www', $view->fpmStats['Pool']);
        self::assertSame(3, $view->fpmStats['Max children reached']);
    }

    public function testCliWithoutOpcacheApcuOrFpm(): void
    {
        $view = new PhpView($this->snapshot([
            'version' => '8.4.23',
            'sapi_name' => 'cli',
            'memory_limit' => '-1',
            'loaded_extensions' => ['Core'],
            'opcache' => [],
            'apcu' => [],
            'fpm' => [],
        ]), $this->gauges());

        self::assertNull($view->opcacheEnabled);
        self::assertNull($view->opcacheMemory);
        self::assertNull($view->opcacheHitRate);
        self::assertNull($view->apcuEnabled);
        self::assertNull($view->apcuMemory);
        self::assertFalse($view->fpm);
        self::assertNull($view->fpmActive);
        self::assertNull($view->fpmMemoryPeak);
        self::assertSame([], $view->iniFiles);
    }

    public function testUnlimitedMemoryHasNoPeakGauge(): void
    {
        $payload = $this->payload();
        $payload['memory_limit'] = '-1';

        $view = new PhpView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->fpmMemoryPeak);
        self::assertTrue($view->fpmActive?->available());
    }

    public function testFailedCollectorIsUnavailable(): void
    {
        $view = new PhpView(new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', [], 'boom', 1)]), $this->gauges());

        self::assertFalse($view->available);
        self::assertNull($view->version);
        self::assertSame([], $view->extensions);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'version' => '8.4.23',
            'sapi_name' => 'fpm-fcgi',
            'ini_file' => '/etc/php/8.4/fpm/php.ini',
            'ini_files' => ['/etc/php/8.4/fpm/conf.d/10-opcache.ini'],
            'expose_php' => false,
            'memory_limit' => '128M',
            'max_execution_time' => 30,
            'max_input_time' => 60,
            'max_input_vars' => 1000,
            'realpath_cache_used_size' => 120000,
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 600,
            'post_max_size' => '8M',
            'upload_max_filesize' => '2M',
            'display_errors' => '0',
            'display_startup_errors' => false,
            'log_errors' => true,
            'error_log' => '/var/log/php/error.log',
            'error_reporting' => 32767,
            'date.timezone' => 'UTC',
            'loaded_extensions' => ['Core', 'json', 'Zend OPcache', 'apcu'],
            'opcache' => [
                'config' => [
                    'directives' => [
                        'opcache.enable' => true,
                        'opcache.enable_cli' => false,
                        'opcache.memory_consumption' => 134217728,
                        'opcache.interned_strings_buffer' => 16,
                        'opcache.max_accelerated_files' => 20000,
                        'opcache.validate_timestamps' => false,
                        'opcache.revalidate_freq' => 2,
                        'opcache.jit' => 'tracing',
                        'opcache.jit_buffer_size' => 67108864,
                    ],
                    'version' => ['version' => '8.4.23', 'opcache_product_name' => 'Zend OPcache'],
                ],
                'status' => [
                    'opcache_enabled' => true,
                    'memory_usage' => ['used_memory' => 60000000, 'free_memory' => 30000000, 'wasted_memory' => 10000000, 'current_wasted_percentage' => 10.0],
                    'opcache_statistics' => ['num_cached_scripts' => 1234, 'num_cached_keys' => 1500, 'max_cached_keys' => 32531, 'hits' => 1090, 'misses' => 110, 'opcache_hit_rate' => 90.8, 'oom_restarts' => 0, 'hash_restarts' => 0, 'manual_restarts' => 1],
                ],
            ],
            'apcu' => [
                'config' => ['apc.enabled' => true, 'apc.enable_cli' => false, 'apc.shm_size' => '32M', 'apc.shm_segments' => 1, 'apc.ttl' => 0],
                'cache_info' => ['num_slots' => 4099, 'ttl' => 0, 'num_hits' => 900, 'num_misses' => 100, 'num_inserts' => 120, 'num_entries' => 42, 'expunges' => 0, 'start_time' => 1760000000, 'mem_size' => 25165824],
                'sma_info' => ['num_seg' => 1, 'seg_size' => 33554432, 'avail_mem' => 8388608],
            ],
            'fpm' => [
                'pool' => 'www',
                'process-manager' => 'dynamic',
                'start-time' => 1760000000,
                'start-since' => 3600,
                'accepted-conn' => 5000,
                'listen-queue' => 0,
                'max-listen-queue' => 2,
                'listen-queue-len' => 511,
                'idle-processes' => 6,
                'active-processes' => 2,
                'total-processes' => 8,
                'max-active-processes' => 7,
                'max-children-reached' => 3,
                'slow-requests' => 1,
                'memory-peak' => 67108864,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', $data, null, 1_000, $delta)]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
