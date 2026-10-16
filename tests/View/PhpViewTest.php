<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\PhpView;
use PHPUnit\Framework\TestCase;

final class PhpViewTest extends TestCase
{
    public function testFullPayloadUnderFpm(): void
    {
        $previous = ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1000, 'misses' => 100]]], 'fpm' => ['accepted-conn' => 4950]];
        $current = ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1090, 'misses' => 110]]], 'fpm' => ['accepted-conn' => 5000]];
        $delta = new Delta(Delta::flatten($previous), Delta::flatten($current), 10);

        $view = new PhpView($this->snapshot(Payloads::php(), $delta), $this->gauges());

        self::assertSame('8.4.23', $view->version);
        self::assertSame('fpm-fcgi', $view->sapi);
        self::assertSame(['/etc/php/8.4/fpm/php.ini', '/etc/php/8.4/fpm/conf.d/10-opcache.ini'], $view->iniFiles, 'the loaded ini file comes first');
        self::assertSame(['Core', 'json', 'Zend OPcache', 'apcu'], $view->extensions);

        self::assertSame(134_217_728, $view->memoryLimit);
        self::assertSame(30, $view->maxExecutionTime);
        self::assertNull($view->memoryLimitUnlimited);
        self::assertNull($view->maxExecutionTimeUnlimited);
        self::assertSame(BadgeStyle::Success, self::badge($view->displayErrors)->style);
        self::assertSame('0', self::badge($view->displayErrors)->label);
        self::assertSame(BadgeStyle::Success, $view->logErrors?->style);
        self::assertSame('/var/log/php/error.log', $view->errorLog);
        self::assertSame('E_ALL', $view->errorReporting);
        self::assertSame('8M', $view->postMaxSize);
        self::assertSame('2M', $view->uploadMaxFilesize);
        self::assertSame('UTC', $view->timezone);

        self::assertTrue($view->opcache);
        self::assertSame('Enabled', $view->opcacheEnabled->label);
        self::assertSame('CLI: disabled', $view->opcacheCli->label);
        self::assertSame(60.0, $view->opcacheMemory->value);
        self::assertSame(BadgeStyle::Success, $view->opcacheMemory->badge?->style);
        self::assertSame(100_000_000, $view->opcacheTotal);
        self::assertSame(90.0, $view->opcacheHitRate->value, 'hit rate from the delta: 90 hits for 10 misses');
        self::assertNull($view->opcacheHitRate->hint);
        self::assertSame(134_217_728, $view->opcacheMemoryConsumption);
        self::assertSame('Disabled', $view->opcachePreload->label);
        self::assertSame('Disabled', self::badge($view->opcacheRevalidateFreq)->label, 'validate_timestamps is off');
        self::assertSame(20000, $view->opcacheMaxAcceleratedFiles);

        self::assertTrue($view->apcu);
        self::assertSame('Enabled', $view->apcuEnabled->label);
        self::assertSame(75.0, $view->apcuMemory->value);
        self::assertSame(90.0, $view->apcuHitRate->value);
        self::assertSame('since server start', $view->apcuHitRate->hint, 'no APCu counters in the delta: cumulative value');
        self::assertSame(33_554_432, $view->apcuShmSize);
        self::assertSame(1, $view->apcuSegments);
        self::assertSame(0, $view->apcuTtl);

        self::assertTrue($view->fpm);
        self::assertSame(5.0, $view->fpmRequestsPerSec->value, '50 connections in 10 seconds');
        self::assertSame(25.0, $view->fpmActiveProcesses->value, '2 active out of 2 + 6');
        self::assertSame(6, $view->fpmIdle);
        self::assertSame(3, $view->fpmMaxChildrenReached);
        self::assertSame('Reached', $view->fpmMaxChildrenReachedBadge?->label);
        self::assertSame('Detected', $view->fpmSlowRequestsBadge?->label);
        self::assertSame(50.0, $view->fpmMemoryPeakPercent?->value, '64 MB peak for a 128M memory_limit');
        self::assertSame('www', $view->fpmPool);
        self::assertSame('dynamic', $view->fpmProcessManager);
        self::assertSame(3600, $view->fpmUptime);
    }

    public function testCliWithoutOpcacheApcuOrFpm(): void
    {
        $view = new PhpView($this->snapshot([
            'version' => '8.4.23',
            'sapi_name' => 'cli',
            'memory_limit' => '-1',
            'display_errors' => 'stderr',
            'loaded_extensions' => ['Core'],
            'opcache' => [],
            'apcu' => [],
            'fpm' => [],
        ]), $this->gauges());

        self::assertFalse($view->opcache);
        self::assertFalse($view->apcu);
        self::assertFalse($view->fpm);
        self::assertNull($view->memoryLimit, 'no limit');
        self::assertSame('Unlimited', $view->memoryLimitUnlimited?->label);
        self::assertSame('stderr', $view->displayErrors);
        self::assertSame([], $view->iniFiles);
    }

    public function testUnlimitedMemoryShowsThePeakAsAValue(): void
    {
        $payload = Payloads::php();
        $payload['memory_limit'] = '-1';

        $view = new PhpView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->fpmMemoryPeakPercent);
        self::assertSame(67_108_864, $view->fpmMemoryPeak);
    }

    public function testNoMemoryPeakGaugeWithoutThePeak(): void
    {
        $payload = Payloads::php();
        unset($payload['fpm']['memory-peak']);

        $view = new PhpView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->fpmMemoryPeakPercent, 'PHP-FPM before 8.2 reports no memory peak');
    }

    public function testNoExecutionTimeLimitIsUnlimited(): void
    {
        $view = new PhpView($this->snapshot(['max_execution_time' => 0] + Payloads::php()), $this->gauges());

        self::assertNull($view->maxExecutionTime);
        self::assertSame('Unlimited', $view->maxExecutionTimeUnlimited?->label);
    }

    public function testOpcacheTurnedOffAtRuntimeIsDisabled(): void
    {
        $payload = Payloads::php();
        $payload['opcache']['status']['opcache_enabled'] = false;

        $view = new PhpView($this->snapshot($payload), $this->gauges());

        self::assertSame('Disabled', $view->opcacheEnabled->label, 'opcache.enable is on but the accelerator is not');
    }

    public function testApcuSegmentsIgnoreTheDirectiveSentBeforeCollector223(): void
    {
        $payload = Payloads::php();
        $payload['apcu']['config']['apc.shm_segments'] = 4;

        $view = new PhpView($this->snapshot($payload), $this->gauges());

        self::assertSame(1, $view->apcuSegments, 'APCu ignores the directive in MMAP mode');
    }

    public function testDisabledApcuHasNoSegments(): void
    {
        $payload = Payloads::php();
        $payload['apcu']['config']['apc.enabled'] = false;
        $payload['apcu']['sma_info'] = [];

        $view = new PhpView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->apcuSegments, 'no segment allocated');
    }

    public function testFailedCollectorIsUnavailable(): void
    {
        $view = new PhpView(new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', [], 'boom', 1)]), $this->gauges());

        self::assertNull($view->version);
        self::assertSame([], $view->extensions);
    }

    private static function badge(mixed $value): Badge
    {
        self::assertInstanceOf(Badge::class, $value);

        return $value;
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
