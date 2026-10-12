<?php

declare(strict_types=1);

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;

/*
 * Thresholds of the gauges: [min inclusive, max exclusive, style, label]. The value 100
 * also matches the range that ends at 100.
 */
return [
    'system.ram_usage' => [
        [0, 70, BadgeStyle::Success, 'Healthy'],
        [70, 85, BadgeStyle::Warning, 'Warning'],
        [85, 100, BadgeStyle::Danger, 'Critical'],
    ],
    'system.cpu_usage' => [
        [0, 60, BadgeStyle::Success, 'Healthy'],
        [60, 80, BadgeStyle::Warning, 'Warning'],
        [80, 100, BadgeStyle::Danger, 'Critical'],
    ],
    'system.disk_usage' => [
        [0, 70, BadgeStyle::Success, 'Healthy'],
        [70, 85, BadgeStyle::Warning, 'Warning'],
        [85, 100, BadgeStyle::Danger, 'Critical'],
    ],
    'php.opcache_memory' => [
        [0, 70, BadgeStyle::Success, 'Healthy'],
        [70, 90, BadgeStyle::Warning, 'Cache Almost Full'],
        [90, 100, BadgeStyle::Danger, 'Cache Full'],
    ],
    'php.opcache_hit_rate' => [
        [95, 100, BadgeStyle::Success, 'Optimal'],
        [85, 95, BadgeStyle::Warning, 'Suboptimal'],
        [0, 85, BadgeStyle::Danger, 'Inefficient'],
    ],
    'php.fpm_active_processes' => [
        [0, 60, BadgeStyle::Success, 'Healthy'],
        [60, 85, BadgeStyle::Warning, 'High Load'],
        [85, 100, BadgeStyle::Danger, 'Saturated'],
    ],
    'php.fpm_memory_peak_percent' => [
        [0, 70, BadgeStyle::Success, 'Safe'],
        [70, 90, BadgeStyle::Warning, 'Close to Limit'],
        [90, 100, BadgeStyle::Danger, 'Memory Limit Risk'],
    ],
    'php.apcu_memory_usage' => [
        [0, 70, BadgeStyle::Success, 'Enough Cache'],
        [70, 90, BadgeStyle::Warning, 'Cache Almost Full'],
        [90, 100, BadgeStyle::Danger, 'Cache Full'],
    ],
    'php.apcu_hit_rate' => [
        [95, 100, BadgeStyle::Success, 'Efficient'],
        [85, 95, BadgeStyle::Warning, 'Partially Efficient'],
        [0, 85, BadgeStyle::Danger, 'Inefficient'],
    ],
    'mysql.connections_usage' => [
        [0, 60, BadgeStyle::Success, 'Available Capacity'],
        [60, 85, BadgeStyle::Warning, 'High Usage'],
        [85, 100, BadgeStyle::Danger, 'Connection Limit Risk'],
    ],
    'mysql.innodb_buffer_pool_usage' => [
        [90, 100, BadgeStyle::Info, 'Warm / Loaded'],
        [30, 90, BadgeStyle::Info, 'Warming Up'],
        [0, 30, BadgeStyle::Warning, 'Cold / Underused'],
    ],
    'mysql.innodb_buffer_pool_hit_rate' => [
        [98, 100, BadgeStyle::Success, 'Optimal'],
        [90, 98, BadgeStyle::Warning, 'Suboptimal'],
        [0, 90, BadgeStyle::Danger, 'Poor Cache Efficiency'],
    ],
    'mysql.tables_lock_waits_percent' => [
        [0, 1, BadgeStyle::Success, 'Minimal Contention'],
        [1, 5, BadgeStyle::Warning, 'Lock Contention'],
        [5, 100, BadgeStyle::Danger, 'High Lock Contention'],
    ],
    'mysql.thread_cache_miss' => [
        [0, 1, BadgeStyle::Success, 'Efficient'],
        [1, 5, BadgeStyle::Warning, 'Moderate Miss Rate'],
        [5, 100, BadgeStyle::Danger, 'High Miss Rate'],
    ],
    'mysql.tmp_tables' => [
        [0, 10, BadgeStyle::Success, 'Mostly In-Memory'],
        [10, 25, BadgeStyle::Warning, 'Frequent Disk Tables'],
        [25, 100, BadgeStyle::Danger, 'Disk-Heavy Queries'],
    ],
    'redis.memory_usage' => [
        [0, 70, BadgeStyle::Success, 'Healthy'],
        [70, 90, BadgeStyle::Warning, 'Memory Pressure'],
        [90, 100, BadgeStyle::Danger, 'Eviction Risk'],
    ],
    'redis.hit_rate' => [
        [95, 100, BadgeStyle::Success, 'Efficient'],
        [80, 95, BadgeStyle::Warning, 'Moderate Efficiency'],
        [0, 80, BadgeStyle::Danger, 'Low Cache Efficiency'],
    ],
];
