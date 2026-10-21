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
                'config' => ['apc.enabled' => true, 'apc.enable_cli' => false, 'apc.shm_size' => '32M', 'apc.ttl' => 0],
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
     * @return array<string, string>
     */
    public static function mysqlStatus(): array
    {
        return [
            'Uptime' => '86400',
            'Threads_connected' => '30',
            'Threads_running' => '3',
            'Threads_created' => '7',
            'Connections' => '1100',
            'Questions' => '1150',
            'Aborted_connects' => '2',
            'Aborted_clients' => '1',
            'Created_tmp_tables' => '150',
            'Created_tmp_disk_tables' => '20',
            'Max_used_connections' => '45',
            'Slow_queries' => '12',
            'Innodb_buffer_pool_read_requests' => '110000',
            'Innodb_buffer_pool_reads' => '1100',
            'Innodb_buffer_pool_pages_total' => '8192',
            'Innodb_buffer_pool_pages_free' => '1024',
            'Innodb_page_size' => '16384',
            'Innodb_data_reads' => '550',
            'Innodb_data_writes' => '220',
            'Table_locks_waited' => '3',
            'Table_locks_immediate' => '1097',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mysqlVariables(): array
    {
        return [
            'version' => '8.4.0',
            'version_comment' => 'MySQL Community Server - GPL',
            'max_connections' => '100',
            'innodb_buffer_pool_size' => '134217728',
            'slow_query_log' => 'ON',
            'long_query_time' => '1.000000',
            'tmp_table_size' => '16777216',
            'max_heap_table_size' => '16777216',
            'thread_cache_size' => '9',
            'table_open_cache' => '4000',
            'wait_timeout' => '28800',
            'log_bin' => 'ON',
            'character_set_server' => 'utf8mb4',
            'collation_server' => 'utf8mb4_0900_ai_ci',
            'time_zone' => 'SYSTEM',
            'system_time_zone' => 'UTC',
            'timestamp' => 1760000000,
            'slow_query_log_file' => '/var/lib/mysql/slow.log',
            'join_buffer_size' => '262144',
            'sort_buffer_size' => '262144',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mysqlSlowQueries(): array
    {
        return [
            'schema_name' => 'app',
            'min_exec_count' => 1,
            'min_avg_time_ms' => 0,
            'limit' => 10,
            'order_by' => 'avg',
            'slow_queries' => [
                ['query_sample' => 'SELECT * FROM `order` WHERE `status` = ?', 'exec_count' => 120, 'total_time_ms' => 36000, 'avg_time_ms' => 300, 'max_time_ms' => 1200],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function redis(): array
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
     * @return array<string, mixed>
     */
    public static function symfony(): array
    {
        return [
            'env' => 'prod',
            'debug' => false,
            'version' => '7.4.2',
            'bundles' => ['TwigBundle', 'FrameworkBundle', 'EasyAdminBundle'],
            'components' => [
                'scheduler' => [
                    ['trigger' => 'every 1 hour', 'command' => 'app:cleanup', 'next_run' => 1_760_003_600, 'description' => 'Clean up'],
                    ['trigger' => 'every 5 minutes with 0-10 second jitter', 'command' => 'app:ping', 'next_run' => 1_760_000_300, 'description' => 'Ping'],
                ],
                'flex_recipes' => ['up_to_date' => false, 'outdated_recipes' => ['symfony/framework-bundle', 'symfony/twig-bundle']],
                'messenger' => ['transports' => ['async' => ['count' => 3], 'failed' => ['count' => 1], 'retry' => ['count' => 0]], 'uncountable_transports' => ['sync']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function postgresqlActivity(): array
    {
        return [
            'database_stats' => ['numbackends' => 12, 'xact_commit' => 50_000, 'xact_rollback' => 500, 'blks_read' => 2_000, 'blks_hit' => 398_000, 'tup_returned' => 1_000_000, 'tup_fetched' => 500_000, 'tup_inserted' => 10_000, 'tup_updated' => 4_000, 'tup_deleted' => 1_000, 'conflicts' => 0, 'deadlocks' => 2, 'temp_files' => 10, 'temp_bytes' => 10_485_760],
            'bgwriter' => ['checkpoints_timed' => 100, 'checkpoints_req' => 5, 'buffers_checkpoint' => 1_000, 'buffers_clean' => 50, 'maxwritten_clean' => 0, 'buffers_alloc' => 5_000],
            'connections' => ['active' => 3, 'idle' => 8, 'idle in transaction' => 1],
            'sessions' => ['oldest_transaction_seconds' => 42, 'idle_in_transaction_count' => 1, 'oldest_idle_in_transaction_seconds' => 15, 'blocked_count' => 1, 'max_wait_seconds' => 7, 'blocked_queries' => [
                ['blocked_pid' => 101, 'blocked_wait_seconds' => 7, 'blocked_query_sample' => 'UPDATE account SET balance = balance - 1 WHERE id = 1', 'blocking_pid' => 99, 'blocking_query_sample' => 'SELECT * FROM account FOR UPDATE', 'blocking_state' => 'idle in transaction'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function postgresqlSettings(): array
    {
        return ['server_version' => '16.4 (Debian 16.4-1.pgdg120+1)', 'max_connections' => '100', 'shared_buffers' => 134_217_728, 'effective_cache_size' => 4_294_967_296, 'work_mem' => 4_194_304, 'maintenance_work_mem' => 67_108_864, 'wal_level' => 'replica', 'max_wal_size' => 1_073_741_824, 'checkpoint_completion_target' => '0.9', 'random_page_cost' => '4', 'effective_io_concurrency' => '1', 'log_min_duration_statement' => '-1', 'TimeZone' => 'Etc/UTC', 'autovacuum' => 'on', 'autovacuum_vacuum_scale_factor' => '0.2', 'track_counts' => 'on'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function postgresqlDatabase(): array
    {
        return ['schema' => 'public', 'db_size' => 734_003_200, 'tables' => ['table_count' => 42, 'live_tuples' => 900_000, 'dead_tuples' => 100_000, 'seq_scans' => 5_000, 'idx_scans' => 95_000, 'total_size' => 620_000_000, 'indexes_size' => 120_000_000]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function postgresqlSlowQueries(): array
    {
        return ['min_calls' => 1, 'min_avg_time_ms' => 0.0, 'limit' => 10, 'order_by' => 'avg', 'slow_queries' => [
            ['query_sample' => 'SELECT * FROM customer WHERE email = $1', 'exec_count' => 120, 'total_time_ms' => '36000.00', 'avg_time_ms' => '300.25', 'max_time_ms' => '1200.00', 'stddev_time_ms' => '50.00', 'rows' => 2_400, 'shared_blks_hit' => 9_900, 'shared_blks_read' => 100],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function frankenphpClassic(): array
    {
        return ['version' => '1.4.0', 'mode' => 'classic', 'busy_threads' => 3, 'total_threads' => 16, 'queue_depth' => 0, 'workers' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function frankenphpWorker(): array
    {
        return ['version' => '1.4.0', 'mode' => 'worker', 'busy_threads' => 13, 'total_threads' => 16, 'queue_depth' => 0, 'workers' => [
            ['name' => '/app/public/index.php', 'total_workers' => 8, 'busy_workers' => 2, 'worker_request_time' => 125.5, 'worker_request_count' => 10_000, 'ready_workers' => 8, 'worker_crashes' => 1, 'worker_restarts' => 3, 'worker_queue_depth' => 0],
            ['name' => 'api', 'total_workers' => 4, 'busy_workers' => 4, 'worker_request_time' => 40.0, 'worker_request_count' => 2_000, 'ready_workers' => 4, 'worker_crashes' => 0, 'worker_restarts' => 0, 'worker_queue_depth' => 2],
        ]];
    }

    /**
     * The payload of ApacheCollector for tests/Fixtures/Apache/server-status.txt.
     *
     * @return array<string, mixed>
     */
    public static function apache(int $accesses = 52_000, int $bytes = 1_331_200_000, float $duration = 2_080.0): array
    {
        return [
            'server_version' => 'Apache/2.4.62 (Debian) OpenSSL/3.0.15', 'server_mpm' => 'event', 'uptime' => 1_040_619,
            'load1' => 0.42, 'load5' => 0.37, 'load15' => 0.31,
            'total_accesses' => $accesses, 'total_bytes' => $bytes,
            'requests_per_second' => 0, 'bytes_per_second' => 1_279, 'bytes_per_request' => 25_600, 'duration_per_request' => 40.0,
            'workers' => ['busy' => 3, 'idle' => 47],
            'scoreboard' => ['_' => 47, 'W' => 1, 'K' => 1, 'R' => 1, '.' => 350],
            'modules' => [],
            'total_duration' => $duration,
        ];
    }

    /**
     * The payload of NginxStatusCollector for tests/Fixtures/Nginx/stub_status.txt.
     *
     * @return array{status: array<string, int>}
     */
    public static function nginxStatus(int $accepts = 18_450, int $handled = 18_450, int $requests = 41_200): array
    {
        return ['status' => ['active' => 12, 'accepts' => $accepts, 'handled' => $handled, 'requests' => $requests, 'reading' => 1, 'writing' => 3, 'waiting' => 8]];
    }

    /**
     * The payload of NginxConfigCollector for the command outputs of tests/Fixtures/Nginx and "nproc --all" giving 4.
     *
     * @return array<string, mixed>
     */
    public static function nginxConfig(): array
    {
        return [
            'version' => '1.26.2',
            'modules' => ['http_ssl_module', 'http_stub_status_module', 'http_v2_module'],
            'config' => [
                'config_path' => '/etc/nginx/nginx.conf', 'user' => 'www-data', 'worker_processes' => 'auto',
                'include' => ['/etc/nginx/modules-enabled/*.conf', '/etc/nginx/mime.types', '/etc/nginx/conf.d/*.conf', '/etc/nginx/sites-enabled/*'],
                'worker_connections' => 768, 'sendfile' => 'on', 'tcp_nopush' => 'on', 'tcp_nodelay' => null, 'keepalive_timeout' => 65, 'types_hash_max_size' => 2048,
                'server_tokens' => 'off', 'ssl_protocols' => 'TLSv1.2 TLSv1.3', 'ssl_prefer_server_ciphers' => 'off',
                'access_log' => '/var/log/nginx/access.log', 'error_log' => '/var/log/nginx/error.log warn', 'gzip' => 'on',
            ],
            'cpu_count' => 4,
        ];
    }
}
