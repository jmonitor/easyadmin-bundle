<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Peak;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class MysqlView
{
    public bool $available;
    public ?string $version;
    public ?string $versionComment;

    /** @var array<string, mixed> */
    private array $variables;
    public ?int $serverTime;
    public ?float $longQueryTime;
    public ?string $timeZone;
    public ?string $systemTimeZone;
    public ?int $uptime;

    public ?int $threadsConnected;
    public ?int $maxConnections;
    public Gauge $connectionsUsage;
    public ?int $maxUsedConnections;
    public ?Peak $maxConnectionsReached;
    public Rate $queriesPerSec;

    public bool $slowQueriesAvailable;
    public bool $slowQueryLogDisabled;
    public ?int $slowQueriesCount;
    /** @var list<array<string, mixed>> */
    public array $slowQueries;
    public ?int $slowQueriesMinAvgTimeMs;
    public ?int $slowQueriesMinExecCount;
    public ?string $slowQueriesOrderBy;

    public Gauge $bufferPoolUsage;
    public ?int $bufferPoolUsed;
    public ?int $bufferPoolTotal;
    public Gauge $bufferPoolHitRate;
    public ?Gauge $ramRatio;
    public ?int $bufferPoolSize;
    public ?int $ramTotal;
    public Rate $innodbReadsPerSec;
    public Rate $innodbWritesPerSec;

    public Rate $tableLocksWaitedPerSec;
    public Gauge $tableLocksWaitsPercent;
    public ?int $tableLocksWaitedDelta;
    public ?int $tableLocksTotalDelta;
    public ?float $tableLocksWaitsPercentDelta;
    public ?float $tableLocksWaitsPercentSinceStart;
    public ?int $threadsRunning;
    public Gauge $threadCacheMiss;
    public Gauge $tmpTables;

    public ?int $dataLength;
    public ?int $indexLength;
    public ?int $totalLength;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges, int $now)
    {
        $status = $snapshot->data('mysql.status');
        $variables = $snapshot->data('mysql.variables');
        $schema = $snapshot->data('mysql.information_schema');
        $slow = $snapshot->data('mysql.slow_queries');
        $delta = $snapshot->delta('mysql.status');

        $this->available = $snapshot->has('mysql.status');
        $this->version = Values::string($variables['version'] ?? null);
        $this->versionComment = Values::string($variables['version_comment'] ?? null);
        $this->variables = $variables;
        $this->serverTime = self::serverTime($snapshot, $now);
        $this->longQueryTime = Values::float($variables['long_query_time'] ?? null);
        $this->timeZone = Values::string($variables['time_zone'] ?? null);
        $this->systemTimeZone = Values::string($variables['system_time_zone'] ?? null);
        $this->uptime = Values::int($status['Uptime'] ?? null);

        $this->threadsConnected = Values::int($status['Threads_connected'] ?? null);
        $this->maxConnections = Values::int($variables['max_connections'] ?? null);
        $this->connectionsUsage = $gauges->create('mysql.connections_usage', 'Connection usage', Values::percent($this->threadsConnected, $this->maxConnections));
        $this->maxUsedConnections = Values::int($status['Max_used_connections'] ?? null);
        $this->maxConnectionsReached = Peak::of($this->threadsConnected, $this->maxUsedConnections, $this->maxConnections);
        $this->queriesPerSec = Ratios::perSecond($delta, 'Questions', 'q/s');

        $this->slowQueriesAvailable = $snapshot->has('mysql.slow_queries');
        $slowQueryLog = $variables['slow_query_log'] ?? null;
        $this->slowQueryLogDisabled = $this->slowQueriesAvailable && $variables !== [] && !in_array($slowQueryLog, ['ON', 'on', '1', 1, true], true);
        $this->slowQueriesCount = Values::int($status['Slow_queries'] ?? null);
        $this->slowQueries = array_values(array_filter(
            is_array($slow['slow_queries'] ?? null) ? $slow['slow_queries'] : [],
            static fn(mixed $row): bool => is_array($row) && isset($row['query_sample']),
        ));
        $this->slowQueriesMinAvgTimeMs = Values::int($slow['min_avg_time_ms'] ?? null);
        $this->slowQueriesMinExecCount = Values::int($slow['min_exec_count'] ?? null);
        $this->slowQueriesOrderBy = Values::string($slow['order_by'] ?? null);

        $this->bufferPoolSize = Values::int($variables['innodb_buffer_pool_size'] ?? null);
        [$this->bufferPoolUsed, $this->bufferPoolTotal] = self::bufferPool($status, $this->bufferPoolSize);
        $this->bufferPoolUsage = $gauges->create('mysql.innodb_buffer_pool_usage', 'Buffer Pool usage', Values::percent($this->bufferPoolUsed, $this->bufferPoolTotal));
        $this->bufferPoolHitRate = $gauges->fromRate('mysql.innodb_buffer_pool_hit_rate', 'Buffer Pool Hit rate', Ratios::complement(
            $delta,
            'Innodb_buffer_pool_reads',
            'Innodb_buffer_pool_read_requests',
            Values::int($status['Innodb_buffer_pool_reads'] ?? null),
            Values::int($status['Innodb_buffer_pool_read_requests'] ?? null),
        ), 2);
        $this->ramTotal = Values::int($snapshot->data('system.ram_total')['total'] ?? null);
        $this->ramRatio = $snapshot->result('system.ram_total') === null
            ? null
            : $gauges->create('mysql.innodb_ram_ratio', 'RAM ratio', Values::percent($this->bufferPoolSize, $this->ramTotal));
        $this->innodbReadsPerSec = Ratios::perSecond($delta, 'Innodb_data_reads', 'reads/s');
        $this->innodbWritesPerSec = Ratios::perSecond($delta, 'Innodb_data_writes', 'writes/s');

        $waited = Values::int($status['Table_locks_waited'] ?? null);
        $immediate = Values::int($status['Table_locks_immediate'] ?? null);
        $this->tableLocksWaitedPerSec = Ratios::perSecond($delta, 'Table_locks_waited', '');
        $waitedDelta = $delta?->getValue('Table_locks_waited');
        $immediateDelta = $delta?->getValue('Table_locks_immediate');
        $this->tableLocksWaitedDelta = $waitedDelta === null ? null : (int) $waitedDelta;
        $this->tableLocksTotalDelta = $waitedDelta === null || $immediateDelta === null ? null : (int) ($waitedDelta + $immediateDelta);
        $this->tableLocksWaitsPercentDelta = Ratios::share($delta, 'Table_locks_waited', 'Table_locks_immediate', null, null)->value;
        $this->tableLocksWaitsPercentSinceStart = Ratios::share(null, 'Table_locks_waited', 'Table_locks_immediate', $waited, $immediate)->value;
        $locks = Ratios::share($delta, 'Table_locks_waited', 'Table_locks_immediate', $waited, $immediate);
        $this->tableLocksWaitsPercent = $gauges->fromRate('mysql.tables_lock_waits_percent', 'Ratio', $locks, 2, $locks->fraction());

        $this->threadsRunning = Values::int($status['Threads_running'] ?? null);
        $threadCacheMiss = Ratios::percent($delta, 'Threads_created', 'Connections', Values::int($status['Threads_created'] ?? null), Values::int($status['Connections'] ?? null));
        $this->threadCacheMiss = $gauges->fromRate('mysql.thread_cache_miss', 'Cache miss', $threadCacheMiss, 1, $threadCacheMiss->fraction());
        $tmpTables = Ratios::percent($delta, 'Created_tmp_disk_tables', 'Created_tmp_tables', Values::int($status['Created_tmp_disk_tables'] ?? null), Values::int($status['Created_tmp_tables'] ?? null));
        $this->tmpTables = $gauges->fromRate('mysql.tmp_tables', 'Temporary tables', $tmpTables, 2, $tmpTables->fraction());

        $this->dataLength = Values::int($schema['data_weight']['data_length'] ?? null);
        $this->indexLength = Values::int($schema['data_weight']['index_length'] ?? null);
        $this->totalLength = $this->dataLength !== null && $this->indexLength !== null ? $this->dataLength + $this->indexLength : null;
    }

    public function variable(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    /**
     * The variables are cached for long, so the server clock is replayed from its offset at collect time.
     */
    private static function serverTime(ComponentSnapshot $snapshot, int $now): ?int
    {
        $result = $snapshot->result('mysql.variables');
        $timestamp = Values::int($result?->data['timestamp'] ?? null);

        return $result === null || $timestamp === null ? null : $now + $timestamp - $result->collectedAt;
    }

    /**
     * MariaDB reports the free bytes of the pool; MySQL only the pages.
     *
     * @param array<string, mixed> $status
     *
     * @return array{?int, ?int} used and total bytes
     */
    private static function bufferPool(array $status, ?int $poolSize): array
    {
        $bytesFree = Values::int($status['Innodb_buffer_pool_bytes_free'] ?? null);

        if ($bytesFree !== null && $poolSize !== null && $poolSize > 0) {
            return [$poolSize - $bytesFree, $poolSize];
        }

        $pagesTotal = Values::int($status['Innodb_buffer_pool_pages_total'] ?? null);
        $pagesFree = Values::int($status['Innodb_buffer_pool_pages_free'] ?? null);
        $pageSize = Values::int($status['Innodb_page_size'] ?? null);

        if ($pagesTotal === null || $pagesFree === null || $pageSize === null || $pagesTotal <= 0) {
            return [null, null];
        }

        return [($pagesTotal - $pagesFree) * $pageSize, $pagesTotal * $pageSize];
    }
}
