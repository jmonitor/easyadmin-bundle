<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class MysqlView
{
    private const VARIABLES = [
        'innodb_buffer_pool_size', 'max_connections', 'tmp_table_size', 'max_heap_table_size', 'sort_buffer_size',
        'join_buffer_size', 'thread_cache_size', 'table_open_cache', 'wait_timeout', 'log_bin', 'slow_query_log',
        'long_query_time', 'character_set_server', 'collation_server', 'time_zone',
    ];

    public bool $available;
    public ?string $version;
    public ?string $versionComment;
    public ?string $schema;
    public ?int $uptime;
    public ?int $threadsConnected;
    public ?int $threadsRunning;
    public ?int $maxUsedConnections;
    public ?int $maxConnections;
    public ?int $connectionsTotal;
    public ?int $abortedConnects;
    public ?int $abortedClients;
    public ?int $slowQueriesTotal;
    public ?int $dataLength;
    public ?int $indexLength;
    public ?bool $slowQueryLog;
    public ?float $longQueryTime;

    public Gauge $connections;
    public ?Gauge $bufferPoolUsage;
    public ?Gauge $bufferPoolHitRate;
    public ?Gauge $tmpDiskTables;
    public ?Gauge $threadCacheMiss;
    public ?Gauge $lockWaits;

    public Rate $questionsPerSec;
    public Rate $innodbReadsPerSec;
    public Rate $innodbWritesPerSec;

    public bool $slowQueriesAvailable;
    public ?string $slowQueriesError;
    /** @var list<array{query_sample: string, exec_count: int, total_time_ms: int, avg_time_ms: int, max_time_ms: int}> */
    public array $slowQueries;
    /** @var array<string, mixed> */
    public array $variables;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $status = $snapshot->data('mysql.status');
        $variables = $snapshot->data('mysql.variables');
        $schema = $snapshot->data('mysql.information_schema');
        $slow = $snapshot->data('mysql.slow_queries');
        $delta = $snapshot->delta('mysql.status');

        $this->available = $snapshot->has('mysql.status');
        $this->version = Values::string($variables['version'] ?? null);
        $this->versionComment = Values::string($variables['version_comment'] ?? null);
        $this->schema = Values::string($schema['schema_name'] ?? null);
        $this->uptime = Values::int($status['Uptime'] ?? null);
        $this->threadsConnected = Values::int($status['Threads_connected'] ?? null);
        $this->threadsRunning = Values::int($status['Threads_running'] ?? null);
        $this->maxUsedConnections = Values::int($status['Max_used_connections'] ?? null);
        $this->maxConnections = Values::int($variables['max_connections'] ?? null);
        $this->connectionsTotal = Values::int($status['Connections'] ?? null);
        $this->abortedConnects = Values::int($status['Aborted_connects'] ?? null);
        $this->abortedClients = Values::int($status['Aborted_clients'] ?? null);
        $this->slowQueriesTotal = Values::int($status['Slow_queries'] ?? null);
        $this->dataLength = Values::int($schema['data_weight']['data_length'] ?? null);
        $this->indexLength = Values::int($schema['data_weight']['index_length'] ?? null);
        $this->slowQueryLog = Values::bool($variables['slow_query_log'] ?? null);
        $this->longQueryTime = Values::float($variables['long_query_time'] ?? null);

        $this->connections = $gauges->create('mysql.connections_usage', 'Connections usage', Values::percent($this->threadsConnected, $this->maxConnections));
        $this->bufferPoolUsage = $status === [] ? null : $gauges->create('mysql.innodb_buffer_pool_usage', 'Buffer pool usage', $this->bufferPoolUsage($status, Values::int($variables['innodb_buffer_pool_size'] ?? null)), 1);
        $this->bufferPoolHitRate = $status === [] ? null : $gauges->fromRate('mysql.innodb_buffer_pool_hit_rate', 'Buffer pool hit rate', Ratios::complement(
            $delta,
            'Innodb_buffer_pool_reads',
            'Innodb_buffer_pool_read_requests',
            Values::int($status['Innodb_buffer_pool_reads'] ?? null),
            Values::int($status['Innodb_buffer_pool_read_requests'] ?? null),
        ));
        $this->tmpDiskTables = $status === [] ? null : $gauges->fromRate('mysql.tmp_tables', 'Temporary tables on disk', Ratios::percent(
            $delta,
            'Created_tmp_disk_tables',
            'Created_tmp_tables',
            Values::int($status['Created_tmp_disk_tables'] ?? null),
            Values::int($status['Created_tmp_tables'] ?? null),
        ), 2);
        $this->threadCacheMiss = $status === [] ? null : $gauges->fromRate('mysql.thread_cache_miss', 'Thread cache miss', Ratios::percent(
            $delta,
            'Threads_created',
            'Connections',
            Values::int($status['Threads_created'] ?? null),
            $this->connectionsTotal,
        ), 2);
        $this->lockWaits = $status === [] ? null : $gauges->fromRate('mysql.tables_lock_waits_percent', 'Table lock waits', Ratios::share(
            $delta,
            'Table_locks_waited',
            'Table_locks_immediate',
            Values::int($status['Table_locks_waited'] ?? null),
            Values::int($status['Table_locks_immediate'] ?? null),
        ), 2);

        $this->questionsPerSec = Ratios::perSecond($delta, 'Questions', 'q/s');
        $this->innodbReadsPerSec = Ratios::perSecond($delta, 'Innodb_data_reads', 'reads/s');
        $this->innodbWritesPerSec = Ratios::perSecond($delta, 'Innodb_data_writes', 'writes/s');

        $this->slowQueriesAvailable = $snapshot->has('mysql.slow_queries');
        $this->slowQueriesError = $snapshot->result('mysql.slow_queries')?->error;
        $this->slowQueries = array_values(array_filter(
            is_array($slow['slow_queries'] ?? null) ? $slow['slow_queries'] : [],
            static fn(mixed $row): bool => is_array($row) && isset($row['query_sample']),
        ));

        $exposed = [];
        foreach (self::VARIABLES as $name) {
            if (array_key_exists($name, $variables)) {
                $exposed[$name] = $variables[$name];
            }
        }
        $this->variables = $exposed;
    }

    /**
     * MariaDB reports the free bytes of the pool; MySQL only the pages.
     *
     * @param array<string, mixed> $status
     */
    private function bufferPoolUsage(array $status, ?int $poolSize): ?float
    {
        $bytesFree = Values::int($status['Innodb_buffer_pool_bytes_free'] ?? null);

        if ($bytesFree !== null && $poolSize !== null && $poolSize > 0) {
            return ($poolSize - $bytesFree) / $poolSize * 100;
        }

        $pagesTotal = Values::int($status['Innodb_buffer_pool_pages_total'] ?? null);
        $pagesFree = Values::int($status['Innodb_buffer_pool_pages_free'] ?? null);

        if ($pagesTotal === null || $pagesFree === null || $pagesTotal <= 0) {
            return null;
        }

        return ($pagesTotal - $pagesFree) / $pagesTotal * 100;
    }
}
