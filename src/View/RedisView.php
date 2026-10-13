<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class RedisView
{
    public bool $available;
    public ?string $version;
    public ?string $mode;
    public ?string $role;
    public ?string $maxMemoryPolicy;
    public ?int $port;
    public ?int $uptime;
    public ?int $usedMemory;
    public ?int $peakMemory;
    public ?int $rssMemory;
    public ?int $maxMemory;
    public ?int $clients;
    public ?int $totalConnections;
    public ?int $totalCommands;
    public ?int $rejectedConnections;
    public ?int $evictedKeys;
    public ?int $expiredKeys;
    public ?int $evictedClients;
    public ?int $errorReplies;
    public ?int $connectedReplicas;
    public ?int $opsPerSec;
    public ?Gauge $memory;
    public Gauge $hitRate;
    /** @var array<string, mixed> */
    public array $persistence;
    /** @var list<array{name: string, keys: ?int, expires: ?int, avg_ttl: ?int}> */
    public array $databases;
    public ?string $saveConfig;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $data = $snapshot->data('redis');
        $delta = $snapshot->delta('redis');
        $server = $data['server'] ?? [];
        $memory = $data['memory'] ?? [];
        $stats = $data['stats'] ?? [];
        $persistence = $data['persistence'] ?? [];
        $replication = $data['replication'] ?? [];

        $this->available = $snapshot->has('redis');
        $this->version = Values::string($server['version'] ?? null);
        $this->mode = Values::string($server['mode'] ?? null);
        $this->port = Values::int($server['port'] ?? null);
        $this->uptime = Values::int($server['uptime'] ?? null);
        $this->clients = Values::int($data['clients']['connected'] ?? null);
        $this->usedMemory = Values::int($memory['used'] ?? null);
        $this->rssMemory = Values::int($memory['used_rss'] ?? null);
        $this->peakMemory = Values::int($memory['used_peak'] ?? null);
        $this->maxMemory = Values::int($memory['max_memory'] ?? null);
        $this->maxMemoryPolicy = Values::string($memory['max_memory_policy'] ?? null);
        $this->totalConnections = Values::int($stats['total_connections_received'] ?? null);
        $this->totalCommands = Values::int($stats['total_commands_processed'] ?? null);
        $this->opsPerSec = Values::int($stats['instantaneous_ops_per_sec'] ?? null);
        $this->rejectedConnections = Values::int($stats['rejected_connections'] ?? null);
        $this->expiredKeys = Values::int($stats['expired_keys'] ?? null);
        $this->evictedKeys = Values::int($stats['evicted_keys'] ?? null);
        $this->evictedClients = Values::int($stats['evicted_clients'] ?? null);
        $this->errorReplies = Values::int($stats['total_error_replies'] ?? null);
        $this->role = Values::string($replication['role'] ?? null);
        $this->connectedReplicas = Values::int($replication['connected_slaves'] ?? null);
        $this->saveConfig = Values::string($data['config']['save'] ?? null);

        $this->memory = $this->maxMemory !== null && $this->maxMemory > 0
            ? $gauges->create('redis.memory_usage', 'Memory usage', Values::percent($this->usedMemory, $this->maxMemory), 1)
            : null;
        $this->hitRate = $gauges->fromRate('redis.hit_rate', 'Hit rate', Ratios::share(
            $delta,
            'stats.keyspace_hits',
            'stats.keyspace_misses',
            Values::int($stats['keyspace_hits'] ?? null),
            Values::int($stats['keyspace_misses'] ?? null),
        ));

        $this->persistence = $persistence === [] ? [] : [
            'RDB last save' => Values::int($persistence['rdb_last_save_time'] ?? null),
            'RDB changes since last save' => Values::int($persistence['rdb_changes_since_last_save'] ?? null),
            'RDB last save status' => Values::string($persistence['rdb_last_bgsave_status'] ?? null),
            'RDB save in progress' => Values::bool($persistence['rdb_bgsave_in_progress'] ?? null),
            'AOF enabled' => Values::bool($persistence['aof_enabled'] ?? null),
            'AOF last rewrite status' => Values::string($persistence['aof_last_bgrewrite_status'] ?? null),
            'AOF current size' => Values::int($persistence['aof_current_size'] ?? null),
        ];

        $databases = [];
        foreach (is_array($data['databases'] ?? null) ? $data['databases'] : [] as $name => $database) {
            if (!is_array($database)) {
                continue;
            }

            $databases[] = [
                'name' => (string) $name,
                'keys' => Values::int($database['keys'] ?? null),
                'expires' => Values::int($database['expires'] ?? null),
                'avg_ttl' => Values::int($database['avg_ttl'] ?? null),
            ];
        }
        $this->databases = $databases;
    }
}
