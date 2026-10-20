<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\RateSource;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class PostgresqlView
{
    /** pg_stat_database and pg_stat_user_tables survive a restart; pg_stat_reset() clears them. */
    private const SINCE_STATS_RESET = 'since stats reset';

    public ?string $version;
    public ?string $majorVersion;
    /** @var array<string, mixed> */
    private array $settings;

    public bool $activityAvailable;
    public ?int $numBackends;
    public ?int $maxConnections;
    public Gauge $connectionsUsage;
    public ?int $activeConnections;
    public ?int $idleConnections;
    public ?int $idleInTransactionConnections;

    public Rate $transactionsPerSec;
    public Rate $commitsPerSec;
    public Rate $rollbacksPerSec;
    public Gauge $rollbackRatio;
    public Gauge $cacheHitRatio;
    public Rate $diskReadsPerSec;
    public Rate $tempFilesPerSec;
    public Rate $tempBytesPerSec;
    public Rate $rowsInsertedPerSec;
    public Rate $rowsUpdatedPerSec;
    public Rate $rowsDeletedPerSec;
    public Rate $deadlocksPerSec;
    public ?int $deadlocksTotal;

    public ?int $liveTuples;
    public ?int $deadTuples;
    public Gauge $deadTupleRatio;
    public Gauge $indexUsageRatio;
    public Rate $seqScansPerSec;
    public Rate $idxScansPerSec;

    public ?int $blockedCount;
    public ?int $maxWaitSeconds;
    public ?int $oldestTransactionSeconds;
    public ?int $idleInTransactionCount;
    public ?int $oldestIdleInTransactionSeconds;
    /** @var list<array<string, mixed>> */
    public array $blockedQueries;

    public bool $slowQueriesAvailable;
    /** @var list<array<string, mixed>> */
    public array $slowQueries;
    public ?float $slowQueriesMinAvgTimeMs;
    public ?int $slowQueriesMinCalls;
    public ?string $slowQueriesOrderBy;

    public ?int $dbSize;
    public ?int $tablesTotalSize;
    public ?int $indexesSize;
    public ?int $tableCount;
    public ?string $schema;

    public ?int $sharedBuffers;
    public ?int $ramTotal;
    public ?Gauge $ramRatio;

    public ?Badge $autovacuum;
    public ?Badge $trackCounts;
    public Badge|string|null $logMinDurationStatement;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $activity = $snapshot->data('postgresql.activity');
        $settings = $snapshot->data('postgresql.settings');
        $database = $snapshot->data('postgresql.database');
        $slow = $snapshot->data('postgresql.slow_queries');
        $delta = $snapshot->delta('postgresql.activity');
        $databaseDelta = $snapshot->delta('postgresql.database');
        $sampled = $snapshot->has('postgresql.activity');
        $databaseSampled = $snapshot->has('postgresql.database');
        $stats = is_array($activity['database_stats'] ?? null) ? $activity['database_stats'] : [];
        $sessions = is_array($activity['sessions'] ?? null) ? $activity['sessions'] : [];
        $tables = is_array($database['tables'] ?? null) ? $database['tables'] : [];

        $this->version = Values::string($settings['server_version'] ?? null);
        $this->majorVersion = self::majorVersion($this->version);
        $this->settings = $settings;

        $this->activityAvailable = $snapshot->has('postgresql.activity');
        $this->numBackends = Values::int($stats['numbackends'] ?? null);
        $this->maxConnections = Values::int($settings['max_connections'] ?? null);
        $this->connectionsUsage = $gauges->create('postgres.connections_usage', 'Connections usage', Values::percent($this->numBackends, $this->maxConnections), hint: 'backends of this database');
        $connections = $activity === [] ? null : (is_array($activity['connections'] ?? null) ? $activity['connections'] : []);
        $this->activeConnections = $connections === null ? null : Values::int($connections['active'] ?? 0);
        $this->idleConnections = $connections === null ? null : Values::int($connections['idle'] ?? 0);
        $this->idleInTransactionConnections = $connections === null ? null : Values::int($connections['idle in transaction'] ?? 0);

        $this->commitsPerSec = Ratios::perSecond($delta, 'database_stats.xact_commit', 'tx/s', $sampled);
        $this->rollbacksPerSec = Ratios::perSecond($delta, 'database_stats.xact_rollback', 'tx/s', $sampled);
        $this->transactionsPerSec = self::sum($this->commitsPerSec, $this->rollbacksPerSec, 'tx/s');
        $rollbackRatio = Ratios::share($delta, 'database_stats.xact_rollback', 'database_stats.xact_commit', Values::int($stats['xact_rollback'] ?? null), Values::int($stats['xact_commit'] ?? null), self::SINCE_STATS_RESET);
        $this->rollbackRatio = $gauges->fromRate('postgres.rollback_ratio', 'Rollback ratio', $rollbackRatio, 2, $rollbackRatio->fraction());
        $cacheHitRatio = Ratios::share($delta, 'database_stats.blks_hit', 'database_stats.blks_read', Values::int($stats['blks_hit'] ?? null), Values::int($stats['blks_read'] ?? null), self::SINCE_STATS_RESET);
        $this->cacheHitRatio = $gauges->fromRate('postgres.cache_hit_ratio', 'Cache hit ratio', $cacheHitRatio, 2, $cacheHitRatio->fraction());
        $this->diskReadsPerSec = Ratios::perSecond($delta, 'database_stats.blks_read', 'blocks/s', $sampled);
        $this->tempFilesPerSec = Ratios::perSecond($delta, 'database_stats.temp_files', 'files/s', $sampled);
        $this->tempBytesPerSec = Ratios::perSecond($delta, 'database_stats.temp_bytes', 'B/s', $sampled);
        $this->rowsInsertedPerSec = Ratios::perSecond($delta, 'database_stats.tup_inserted', 'rows/s', $sampled);
        $this->rowsUpdatedPerSec = Ratios::perSecond($delta, 'database_stats.tup_updated', 'rows/s', $sampled);
        $this->rowsDeletedPerSec = Ratios::perSecond($delta, 'database_stats.tup_deleted', 'rows/s', $sampled);
        $this->deadlocksPerSec = Ratios::perSecond($delta, 'database_stats.deadlocks', '/s', $sampled);
        $this->deadlocksTotal = Values::int($stats['deadlocks'] ?? null);

        $this->liveTuples = Values::int($tables['live_tuples'] ?? null);
        $this->deadTuples = Values::int($tables['dead_tuples'] ?? null);
        $this->deadTupleRatio = $gauges->create('postgres.dead_tuple_ratio', 'Dead rows ratio', Values::percent($this->deadTuples, $this->liveTuples === null || $this->deadTuples === null ? null : $this->liveTuples + $this->deadTuples), 2);
        $indexUsage = Ratios::share($databaseDelta, 'tables.idx_scans', 'tables.seq_scans', Values::int($tables['idx_scans'] ?? null), Values::int($tables['seq_scans'] ?? null), self::SINCE_STATS_RESET);
        $this->indexUsageRatio = $gauges->fromRate('postgres.index_usage_ratio', 'Index usage', $indexUsage, 2, $indexUsage->fraction());
        $this->seqScansPerSec = Ratios::perSecond($databaseDelta, 'tables.seq_scans', 'scans/s', $databaseSampled);
        $this->idxScansPerSec = Ratios::perSecond($databaseDelta, 'tables.idx_scans', 'scans/s', $databaseSampled);

        $this->blockedCount = Values::int($sessions['blocked_count'] ?? null);
        $this->maxWaitSeconds = Values::int($sessions['max_wait_seconds'] ?? null);
        $this->oldestTransactionSeconds = Values::int($sessions['oldest_transaction_seconds'] ?? null);
        $this->idleInTransactionCount = Values::int($sessions['idle_in_transaction_count'] ?? null);
        $this->oldestIdleInTransactionSeconds = Values::int($sessions['oldest_idle_in_transaction_seconds'] ?? null);
        $this->blockedQueries = self::rows($sessions['blocked_queries'] ?? null, 'blocked_pid');

        $this->slowQueriesAvailable = $snapshot->has('postgresql.slow_queries');
        $this->slowQueries = array_map(self::slowQuery(...), self::rows($slow['slow_queries'] ?? null, 'query_sample'));
        $this->slowQueriesMinAvgTimeMs = Values::float($slow['min_avg_time_ms'] ?? null);
        $this->slowQueriesMinCalls = Values::int($slow['min_calls'] ?? null);
        $this->slowQueriesOrderBy = Values::string($slow['order_by'] ?? null);

        $this->dbSize = Values::int($database['db_size'] ?? null);
        $this->tablesTotalSize = Values::int($tables['total_size'] ?? null);
        $this->indexesSize = Values::int($tables['indexes_size'] ?? null);
        $this->tableCount = Values::int($tables['table_count'] ?? null);
        $this->schema = Values::string($database['schema'] ?? null);

        $this->sharedBuffers = Values::int($settings['shared_buffers'] ?? null);
        $this->ramTotal = Values::int($snapshot->data('system.ram_total')['total'] ?? null);
        $this->ramRatio = $snapshot->result('system.ram_total') === null
            ? null
            : $gauges->create('postgres.shared_buffers_ram_ratio', 'RAM ratio', Values::percent($this->sharedBuffers, $this->ramTotal), 1);

        $this->autovacuum = self::onOff($settings['autovacuum'] ?? null, BadgeStyle::Danger);
        $this->trackCounts = self::onOff($settings['track_counts'] ?? null, BadgeStyle::Warning);
        $this->logMinDurationStatement = self::logMinDuration($settings['log_min_duration_statement'] ?? null);
    }

    public function setting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * "16.4 (Debian 16.4-1.pgdg120+1)" gives "16", "9.6.24" gives "9".
     */
    private static function majorVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $major = explode('.', explode(' ', $version)[0])[0];

        return $major === '' ? null : $major;
    }

    private static function onOff(mixed $value, BadgeStyle $off): ?Badge
    {
        return match (Values::bool($value)) {
            true => new Badge(BadgeStyle::Success, 'on'),
            false => new Badge($off, 'off'),
            null => null,
        };
    }

    private static function logMinDuration(mixed $value): Badge|string|null
    {
        $ms = Values::int($value);

        if ($ms === null) {
            return null;
        }

        return $ms < 0 ? new Badge(BadgeStyle::Warning, 'disabled') : $ms . ' ms';
    }

    private static function sum(Rate $a, Rate $b, string $unit): Rate
    {
        if ($a->isPending() || $b->isPending()) {
            return Rate::pending($unit);
        }

        if ($a->value === null || $b->value === null) {
            return Rate::unavailable($unit);
        }

        return new Rate(round($a->value + $b->value, 2), RateSource::Delta, $unit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows, string $requiredKey): array
    {
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) && isset($row[$requiredKey])));
    }

    /**
     * pdo_pgsql gives the ROUND(...::numeric) columns as strings.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function slowQuery(array $row): array
    {
        $hit = Values::int($row['shared_blks_hit'] ?? null);
        $read = Values::int($row['shared_blks_read'] ?? null);
        $ratio = Values::percent($hit, $hit === null || $read === null ? null : $hit + $read);

        return [
            'exec_count' => Values::int($row['exec_count'] ?? null),
            'avg_time_ms' => Values::float($row['avg_time_ms'] ?? null),
            'max_time_ms' => Values::float($row['max_time_ms'] ?? null),
            'cache_hit' => $ratio === null ? null : round($ratio, 2),
        ] + $row;
    }
}
