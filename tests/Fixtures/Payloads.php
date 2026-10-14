<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures;

final class Payloads
{
    /**
     * @return array<string, mixed>
     */
    public static function php(): array
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
}
