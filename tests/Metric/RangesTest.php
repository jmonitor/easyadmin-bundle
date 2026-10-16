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
            'redis.memory_usage', 'redis.hit_rate',
        ] as $metric) {
            self::assertNotNull($this->ranges->badge($metric, 50), $metric);
        }
    }
}
