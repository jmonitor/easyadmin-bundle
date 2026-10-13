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
use Jmonitor\EasyAdminBundle\View\RedisView;
use PHPUnit\Framework\TestCase;

final class RedisViewTest extends TestCase
{
    public function testFullPayloadWithAMemoryLimit(): void
    {
        $payload = $this->payload();
        $payload['memory']['max_memory'] = 2_000_000_000;
        $previous = Delta::flatten(['stats' => ['keyspace_hits' => 9000, 'keyspace_misses' => 1000]]);
        $delta = new Delta($previous, Delta::flatten($payload), 10);

        $view = new RedisView($this->snapshot($payload, $delta), $this->gauges());

        self::assertTrue($view->available);
        self::assertSame('7.2.4', $view->version);
        self::assertSame('standalone', $view->mode);
        self::assertSame(6379, $view->port);
        self::assertSame(360_000, $view->uptime);
        self::assertSame(1_500_000_000, $view->usedMemory);
        self::assertSame(2_000_000_000, $view->maxMemory);
        self::assertSame(75.0, $view->memory?->value);
        self::assertSame(BadgeStyle::Warning, $view->memory->badge?->style);
        self::assertSame(95.0, $view->hitRate->value, 'delta: 950 hits for 50 misses');
        self::assertNull($view->hitRate->hint);
        self::assertSame(1234, $view->opsPerSec);
        self::assertSame(12, $view->clients);
        self::assertSame('allkeys-lru', $view->maxMemoryPolicy);
        self::assertSame('master', $view->role);
        self::assertSame(0, $view->connectedReplicas);
        self::assertSame('ok', $view->persistence['RDB last save status']);
        self::assertFalse($view->persistence['AOF enabled']);
        self::assertSame([
            ['name' => 'db0', 'keys' => 1500, 'expires' => 1200, 'avg_ttl' => 3_600_000],
            ['name' => 'db1', 'keys' => 10, 'expires' => 0, 'avg_ttl' => 0],
        ], $view->databases);
        self::assertSame('3600 1 300 100 60 10000', $view->saveConfig);
    }

    public function testNoMaxMemoryMeansNoMemoryGauge(): void
    {
        $view = new RedisView($this->snapshot($this->payload()), $this->gauges());

        self::assertNull($view->memory, 'maxmemory 0 is "no limit": a percentage makes no sense');
        self::assertSame(1_500_000_000, $view->usedMemory);
        self::assertSame(1_600_000_000, $view->peakMemory);
        self::assertSame(90.5, $view->hitRate->value, 'without delta: cumulative 9 950 hits for 1 050 misses, rounded to one decimal');
        self::assertSame('since server start', $view->hitRate->hint);
    }

    public function testFailedCollector(): void
    {
        $view = new RedisView(new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', [], 'Redis exception: Connection refused', 1)]), $this->gauges());

        self::assertFalse($view->available);
        self::assertNull($view->memory);
        self::assertFalse($view->hitRate->available());
        self::assertSame([], $view->databases);
        self::assertSame([], $view->persistence);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'server' => ['version' => '7.2.4', 'mode' => 'standalone', 'port' => 6379, 'uptime' => 360_000],
            'clients' => ['connected' => 12],
            'memory' => ['used' => 1_500_000_000, 'used_rss' => 1_550_000_000, 'used_peak' => 1_600_000_000, 'max_memory' => 0, 'max_memory_policy' => 'allkeys-lru'],
            'persistence' => ['rdb_bgsave_in_progress' => 0, 'rdb_last_save_time' => 1_760_000_000, 'rdb_changes_since_last_save' => 42, 'rdb_last_bgsave_status' => 'ok', 'rdb_last_bgsave_time' => 1, 'aof_enabled' => 0, 'aof_rewrite_in_progress' => 0, 'aof_last_rewrite_time_sec' => -1, 'aof_last_bgrewrite_status' => 'ok', 'aof_last_cow_size' => 0, 'aof_current_size' => 0, 'aof_rewrite_base_size' => 0],
            'stats' => ['total_connections_received' => 5000, 'total_commands_processed' => 900_000, 'instantaneous_ops_per_sec' => 1234, 'rejected_connections' => 0, 'expired_keys' => 300, 'evicted_keys' => 7, 'evicted_clients' => 0, 'keyspace_hits' => 9950, 'keyspace_misses' => 1050, 'tracking_total_keys' => 0, 'total_error_replies' => 3, 'total_reads_processed' => 1, 'total_writes_processed' => 1, 'acl_access_denied_auth' => 0],
            'replication' => ['role' => 'master', 'connected_slaves' => 0],
            'cpu' => ['used_sys' => 12.5, 'used_user' => 30.2],
            'databases' => ['db0' => ['keys' => 1500, 'expires' => 1200, 'avg_ttl' => 3_600_000], 'db1' => ['keys' => '10', 'expires' => '0', 'avg_ttl' => '0']],
            'config' => ['save' => '3600 1 300 100 60 10000'],
        ];
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
