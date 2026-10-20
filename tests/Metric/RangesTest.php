<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Metric;

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use PHPUnit\Framework\TestCase;

final class RangesTest extends TestCase
{
    private Ranges $ranges;

    protected function setUp(): void
    {
        $this->ranges = Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php');
    }

    public function testLowerBoundIsInclusiveUpperBoundExclusive(): void
    {
        self::assertSame(BadgeStyle::Success, $this->ranges->badge('system.ram_usage', 69.9)?->style);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('system.ram_usage', 70)?->style);
        self::assertSame(BadgeStyle::Danger, $this->ranges->badge('system.ram_usage', 85)?->style);
        self::assertSame('Critical', $this->ranges->badge('system.ram_usage', 99)?->label);
    }

    public function testOneHundredMatchesTheRangeThatEndsThere(): void
    {
        self::assertSame(BadgeStyle::Danger, $this->ranges->badge('system.ram_usage', 100)?->style);
        self::assertSame(BadgeStyle::Success, $this->ranges->badge('php.opcache_hit_rate', 100)?->style);
    }

    public function testReversedRangesWork(): void
    {
        self::assertSame('Optimal', $this->ranges->badge('php.opcache_hit_rate', 97.5)?->label);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('php.opcache_hit_rate', 94.9)?->style);
        self::assertSame(BadgeStyle::Danger, $this->ranges->badge('php.opcache_hit_rate', 10)?->style);
        self::assertSame(BadgeStyle::Info, $this->ranges->badge('mysql.innodb_buffer_pool_usage', 95)?->style);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('mysql.innodb_buffer_pool_usage', 10)?->style);
    }

    public function testUnknownMetricOrOutOfRangeValueHasNoBadge(): void
    {
        self::assertNull($this->ranges->badge('mysql.version', 50));
        self::assertNull($this->ranges->badge('system.ram_usage', 150));
        self::assertNull($this->ranges->badge('system.ram_usage', -1));
    }

    public function testEveryGaugeOfTheV1HasThresholds(): void
    {
        foreach ([
            'system.ram_usage', 'system.cpu_usage', 'system.disk_usage',
            'php.opcache_memory', 'php.opcache_hit_rate', 'php.fpm_active_processes', 'php.fpm_memory_peak_percent', 'php.apcu_memory_usage', 'php.apcu_hit_rate',
            'mysql.connections_usage', 'mysql.innodb_buffer_pool_usage', 'mysql.innodb_buffer_pool_hit_rate', 'mysql.tables_lock_waits_percent', 'mysql.thread_cache_miss', 'mysql.tmp_tables',
            'postgres.connections_usage', 'postgres.rollback_ratio', 'postgres.cache_hit_ratio', 'postgres.dead_tuple_ratio', 'postgres.index_usage_ratio', 'postgres.shared_buffers_ram_ratio',
            'redis.memory_usage', 'redis.hit_rate',
            'frankenphp.busy_threads_percent', 'frankenphp.worker_busy_workers_rate',
            'apache.busy_workers', 'apache.busy_process',
        ] as $metric) {
            self::assertNotNull($this->ranges->badge($metric, 50), $metric);
        }
    }

    public function testFrankenPhpThresholds(): void
    {
        foreach (['frankenphp.busy_threads_percent', 'frankenphp.worker_busy_workers_rate'] as $metric) {
            self::assertSame(BadgeStyle::Success, $this->ranges->badge($metric, 59)?->style, $metric);
            self::assertSame('High Load', $this->ranges->badge($metric, 60)?->label, $metric);
            self::assertSame(BadgeStyle::Warning, $this->ranges->badge($metric, 60)?->style, $metric);
            self::assertSame('Saturated', $this->ranges->badge($metric, 85)?->label, $metric);
            self::assertSame(BadgeStyle::Danger, $this->ranges->badge($metric, 100)?->style, $metric);
        }
    }

    public function testApacheThresholdsMatchJmonitorIo(): void
    {
        self::assertSame('Healthy', $this->ranges->badge('apache.busy_workers', 59.9)?->label);
        self::assertSame('Warning', $this->ranges->badge('apache.busy_workers', 60)?->label);
        self::assertSame('Critical', $this->ranges->badge('apache.busy_workers', 85)?->label);
        self::assertSame('Stable', $this->ranges->badge('apache.busy_process', 0.75)?->label);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('apache.busy_process', 60)?->style);
        self::assertSame('Overloaded', $this->ranges->badge('apache.busy_process', 100)?->label);
    }

    public function testPostgresThresholdsMatchJmonitorIo(): void
    {
        self::assertSame(BadgeStyle::Success, $this->ranges->badge('postgres.cache_hit_ratio', 99)?->style);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('postgres.cache_hit_ratio', 98.99)?->style);
        self::assertSame(BadgeStyle::Danger, $this->ranges->badge('postgres.cache_hit_ratio', 94)?->style);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('postgres.dead_tuple_ratio', 10)?->style);
        self::assertSame(BadgeStyle::Success, $this->ranges->badge('postgres.shared_buffers_ram_ratio', 25)?->style);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('postgres.shared_buffers_ram_ratio', 10)?->style);
        self::assertSame(BadgeStyle::Warning, $this->ranges->badge('postgres.shared_buffers_ram_ratio', 50)?->style);
    }
}
