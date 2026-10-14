<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Peak;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class RedisView
{
    private const POLICIES = [
        'noeviction' => 'When memory is full and a new write is attempted, Redis rejects the operation and returns an error.',
        'allkeys-lru' => 'When memory is full and a new write is attempted, Redis evicts the least recently used keys from the entire dataset.',
        'volatile-lru' => 'When memory is full and a new write is attempted, Redis evicts the least recently used keys among those with an expiration set.',
        'allkeys-lfu' => 'When memory is full and a new write is attempted, Redis evicts the least frequently used keys from the entire dataset.',
        'volatile-lfu' => 'When memory is full and a new write is attempted, Redis evicts the least frequently used keys among those with an expiration set.',
        'allkeys-random' => 'When memory is full and a new write is attempted, Redis evicts random keys from the entire dataset.',
        'volatile-random' => 'When memory is full and a new write is attempted, Redis evicts random keys among those with an expiration set.',
        'volatile-ttl' => 'When memory is full and a new write is attempted, Redis evicts keys with an expiration set, prioritizing those with the shortest remaining TTL.',
    ];

    public bool $available;
    public ?string $version;
    public ?string $mode;

    public ?Badge $maxMemoryUnlimited;
    public ?Badge $maxMemoryPolicy;
    public ?string $maxMemoryPolicyDescription;
    public ?int $port;
    public ?int $uptime;
    public ?Badge $role;
    public ?int $connectedSlaves;

    public Badge $rdb;
    public bool $rdbActive;
    public ?int $rdbLastSave;
    public ?int $rdbUnsavedChanges;
    public ?Badge $rdbLastSaveStatus;
    public Badge $aof;
    public bool $aofEnabled;
    public ?Badge $aofLastRewriteStatus;
    public ?int $aofLastRewriteDuration;
    public ?int $aofFileSize;
    public ?string $aofGrowth;

    public ?int $usedMemory;
    public ?int $peakMemory;
    public ?int $maxMemoryBytes;
    public ?Gauge $memoryUsage;
    public ?Peak $memoryPeak;

    public Rate $requestsPerSec;
    public Gauge $hitRate;
    public ?Badge $evictedKeys;
    public ?Badge $rejectedConnections;
    public ?Badge $totalErrors;
    public ?int $clients;

    /** @var list<array{name: string, keys: ?int, expiring: ?float, avg_ttl: ?int}> */
    public array $databases;

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

        $this->maxMemoryBytes = Values::int($memory['max_memory'] ?? null);
        $this->maxMemoryUnlimited = $this->maxMemoryBytes === 0 ? new Badge(BadgeStyle::Danger, 'Unlimited') : null;
        $policy = Values::string($memory['max_memory_policy'] ?? null);
        $this->maxMemoryPolicy = $policy === null ? null : new Badge(BadgeStyle::Info, $policy);
        $this->maxMemoryPolicyDescription = self::POLICIES[$policy] ?? null;
        $this->port = Values::int($server['port'] ?? null);
        $this->uptime = Values::int($server['uptime'] ?? null);
        $role = Values::string($replication['role'] ?? null);
        $this->role = $role === null ? null : new Badge(BadgeStyle::Info, $role);
        $this->connectedSlaves = Values::int($replication['connected_slaves'] ?? null);

        $save = $data['config']['save'] ?? null;
        $this->rdbActive = $save === null || (bool) $save;
        $this->rdb = new Badge(BadgeStyle::Info, $save === null ? 'Uncertain' : ($this->rdbActive ? 'Enabled' : 'Disabled'));
        $this->rdbLastSave = Values::int($persistence['rdb_last_save_time'] ?? null);
        $this->rdbUnsavedChanges = Values::int($persistence['rdb_changes_since_last_save'] ?? null);
        $this->rdbLastSaveStatus = self::status(
            Values::bool($persistence['rdb_bgsave_in_progress'] ?? null),
            Values::string($persistence['rdb_last_bgsave_status'] ?? null),
        );
        $this->aofEnabled = Values::bool($persistence['aof_enabled'] ?? null) === true;
        $this->aof = new Badge(BadgeStyle::Info, $this->aofEnabled ? 'Enabled' : 'Disabled');
        $this->aofLastRewriteStatus = self::status(
            Values::bool($persistence['aof_rewrite_in_progress'] ?? null),
            Values::string($persistence['aof_last_bgrewrite_status'] ?? null),
        );
        $this->aofLastRewriteDuration = Values::int($persistence['aof_last_rewrite_time_sec'] ?? null);
        $this->aofFileSize = Values::int($persistence['aof_current_size'] ?? null);
        $baseSize = Values::int($persistence['aof_rewrite_base_size'] ?? null);
        $growth = $this->aofEnabled && $this->aofFileSize !== null && $baseSize !== null && $baseSize > 0
            ? round(($this->aofFileSize - $baseSize) / $baseSize * 100, 2)
            : null;
        $this->aofGrowth = $growth === null ? null : ($growth > 0 ? '+' : '') . $growth . '%';

        $this->usedMemory = Values::int($memory['used'] ?? null);
        $this->peakMemory = Values::int($memory['used_peak'] ?? null);
        $this->memoryUsage = $this->maxMemoryBytes !== null && $this->maxMemoryBytes > 0
            ? $gauges->create('redis.memory_usage', 'Memory usage', Values::percent($this->usedMemory, $this->maxMemoryBytes), 1)
            : null;
        $this->memoryPeak = Peak::of($this->usedMemory, $this->peakMemory, $this->maxMemoryBytes);

        $this->requestsPerSec = Ratios::perSecond($delta, 'stats.total_commands_processed', 'req/s');
        $this->hitRate = $gauges->fromRate('redis.hit_rate', 'Hit rate', Ratios::share(
            $delta,
            'stats.keyspace_hits',
            'stats.keyspace_misses',
            Values::int($stats['keyspace_hits'] ?? null),
            Values::int($stats['keyspace_misses'] ?? null),
        ));
        $this->evictedKeys = self::count(Values::int($stats['evicted_keys'] ?? null));
        $this->rejectedConnections = self::count(Values::int($stats['rejected_connections'] ?? null));
        $this->totalErrors = self::count(Values::int($stats['total_error_replies'] ?? null));
        $this->clients = Values::int($data['clients']['connected'] ?? null);

        $databases = [];
        foreach (is_array($data['databases'] ?? null) ? $data['databases'] : [] as $name => $database) {
            if (!is_array($database)) {
                continue;
            }

            $keys = Values::int($database['keys'] ?? null);
            $expires = Values::int($database['expires'] ?? null);
            $avgTtl = Values::int($database['avg_ttl'] ?? null);
            $databases[] = [
                'name' => ucfirst((string) $name),
                'keys' => $keys,
                'expiring' => $keys !== null && $expires !== null && $keys > 0 ? round($expires / $keys * 100, 2) : null,
                'avg_ttl' => $avgTtl === null || $avgTtl === 0 ? null : intdiv($avgTtl, 1000),
            ];
        }
        $this->databases = $databases;
    }

    private static function status(?bool $inProgress, ?string $status): ?Badge
    {
        return match (true) {
            $inProgress === true => new Badge(BadgeStyle::Info, 'In progress'),
            $status === null => null,
            $status === 'ok' => new Badge(BadgeStyle::Success, 'ok'),
            default => new Badge(BadgeStyle::Danger, $status),
        };
    }

    private static function count(?int $value): ?Badge
    {
        return match (true) {
            $value === null => null,
            $value === 0 => new Badge(BadgeStyle::Success, '0'),
            default => new Badge(BadgeStyle::Danger, (string) $value),
        };
    }
}
