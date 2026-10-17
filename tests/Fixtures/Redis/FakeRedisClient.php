<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Redis;

/**
 * Answers the two calls of RedisCollector, INFO and CONFIG GET save, without a server.
 */
final class FakeRedisClient
{
    /**
     * @return array<string, int|string>
     */
    public function info(): array
    {
        return [
            'redis_version' => '7.2.4',
            'redis_mode' => 'standalone',
            'tcp_port' => 6379,
            'uptime_in_seconds' => 86400,
            'connected_clients' => 3,
            'used_memory' => 1048576,
            'used_memory_rss' => 2097152,
            'used_memory_peak' => 1572864,
            'maxmemory' => 0,
            'maxmemory_policy' => 'noeviction',
            'total_connections_received' => 10,
            'total_commands_processed' => 1000,
            'instantaneous_ops_per_sec' => 1,
            'keyspace_hits' => 90,
            'keyspace_misses' => 10,
            'role' => 'master',
            'connected_slaves' => 0,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function config(string $operation, string $key): array
    {
        return ['save' => '3600 1'];
    }
}
