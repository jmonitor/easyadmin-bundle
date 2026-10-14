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
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\RedisView;
use PHPUnit\Framework\TestCase;

final class RedisViewTest extends TestCase
{
    public function testFullPayloadWithAMemoryLimit(): void
    {
        $payload = Payloads::redis();
        $payload['memory']['max_memory'] = 2_000_000_000;
        $payload['persistence']['aof_enabled'] = 1;
        $payload['persistence']['aof_current_size'] = 1_100;
        $payload['persistence']['aof_rewrite_base_size'] = 1_000;
        $previous = Delta::flatten(['stats' => ['keyspace_hits' => 9000, 'keyspace_misses' => 1000, 'total_commands_processed' => 899_000]]);
        $delta = new Delta($previous, Delta::flatten($payload), 10);

        $view = new RedisView($this->snapshot($payload, $delta), $this->gauges());

        self::assertSame('7.2.4', $view->version);
        self::assertSame('standalone', $view->mode);
        self::assertSame(2_000_000_000, $view->maxMemoryBytes);
        self::assertNull($view->maxMemoryUnlimited);
        self::assertSame('allkeys-lru', $view->maxMemoryPolicy?->label);
        self::assertStringContainsString('least recently used keys from the entire dataset', (string) $view->maxMemoryPolicyDescription);
        self::assertSame(6379, $view->port);
        self::assertSame(360_000, $view->uptime);
        self::assertSame('master', $view->role?->label);
        self::assertSame(0, $view->connectedSlaves);

        self::assertSame('Enabled', $view->rdb->label);
        self::assertTrue($view->rdbActive);
        self::assertSame(1_760_000_000, $view->rdbLastSave);
        self::assertSame(42, $view->rdbUnsavedChanges);
        self::assertSame(BadgeStyle::Success, $view->rdbLastSaveStatus?->style);
        self::assertTrue($view->aofEnabled);
        self::assertSame('+10%', $view->aofGrowth);

        self::assertSame(75.0, $view->memoryUsage?->value);
        self::assertSame(BadgeStyle::Warning, $view->memoryUsage->badge?->style);
        self::assertSame(80.0, $view->memoryPeak?->peakPercent());

        self::assertSame(100.0, $view->requestsPerSec->value, '1 000 commands in 10 seconds');
        self::assertSame(95.0, $view->hitRate->value, 'delta: 950 hits for 50 misses');
        self::assertNull($view->hitRate->hint);
        self::assertSame('7', $view->evictedKeys?->label);
        self::assertSame(BadgeStyle::Danger, $view->evictedKeys->style);
        self::assertSame(BadgeStyle::Success, $view->rejectedConnections?->style);
        self::assertSame('3', $view->totalErrors?->label);
        self::assertSame(12, $view->clients);

        self::assertSame([
            ['name' => 'Db0', 'keys' => 1500, 'expiring' => 80.0, 'avg_ttl' => 3_600],
            ['name' => 'Db1', 'keys' => 10, 'expiring' => 0.0, 'avg_ttl' => null],
        ], $view->databases);
    }

    public function testNoMaxMemoryMeansUnlimited(): void
    {
        $payload = Payloads::redis();
        unset($payload['config']['save']);

        $view = new RedisView($this->snapshot($payload), $this->gauges());

        self::assertSame('Unlimited', $view->maxMemoryUnlimited?->label);
        self::assertNull($view->memoryUsage, 'maxmemory 0 is "no limit": a percentage makes no sense');
        self::assertNull($view->memoryPeak);
        self::assertSame(1_500_000_000, $view->usedMemory);
        self::assertSame('Uncertain', $view->rdb->label);
        self::assertFalse($view->aofEnabled);
        self::assertNull($view->aofGrowth);
        self::assertTrue($view->requestsPerSec->isUnavailable());
        self::assertSame(90.5, $view->hitRate->value, 'without delta: cumulative 9 950 hits for 1 050 misses, rounded to one decimal');
        self::assertSame('since server start', $view->hitRate->hint);
    }

    public function testFailedCollector(): void
    {
        $view = new RedisView(new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', [], 'Redis exception: Connection refused', 1)]), $this->gauges());

        self::assertNull($view->memoryUsage);
        self::assertFalse($view->hitRate->available());
        self::assertSame([], $view->databases);
        self::assertNull($view->rdbLastSaveStatus);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', $data, null, 1_000, $delta)]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
