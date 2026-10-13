<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\View\MysqlView;
use PHPUnit\Framework\TestCase;

final class MysqlViewTest extends TestCase
{
    public function testGaugesRatesAndFacts(): void
    {
        $previous = ['Questions' => '1000', 'Innodb_data_reads' => '500', 'Innodb_data_writes' => '200', 'Innodb_buffer_pool_read_requests' => '100000', 'Innodb_buffer_pool_reads' => '1000', 'Created_tmp_disk_tables' => '10', 'Created_tmp_tables' => '100', 'Threads_created' => '5', 'Connections' => '1000', 'Table_locks_waited' => '2', 'Table_locks_immediate' => '998'];
        $status = $this->statusPayload();
        $delta = new Delta($previous, Delta::flatten($status), 10);

        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000, $delta),
            'mysql.variables' => new CollectorResult('mysql.variables', $this->variablesPayload(), null, 1_000),
            'mysql.information_schema' => new CollectorResult('mysql.information_schema', ['schema_name' => 'app', 'data_weight' => ['data_length' => 500_000_000, 'index_length' => 120_000_000]], null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', ['schema_name' => 'app', 'limit' => 10, 'slow_queries' => [
                ['query_sample' => 'SELECT * FROM `order` WHERE `status` = ?', 'exec_count' => 120, 'total_time_ms' => 36000, 'avg_time_ms' => 300, 'max_time_ms' => 1200],
            ]], null, 1_000),
        ]), $this->gauges());

        self::assertTrue($view->available);
        self::assertSame('8.4.0', $view->version);
        self::assertSame('MySQL Community Server - GPL', $view->versionComment);
        self::assertSame(86_400, $view->uptime);
        self::assertSame(30, $view->threadsConnected);
        self::assertSame(100, $view->maxConnections);
        self::assertSame(30.0, $view->connections->value);
        self::assertSame(BadgeStyle::Success, $view->connections->badge?->style);

        self::assertSame(87.5, $view->bufferPoolUsage?->value, 'MySQL: pages used out of pages total');
        self::assertSame(99.0, $view->bufferPoolHitRate?->value, 'delta: 10 000 requests, 100 disk reads');
        self::assertNull($view->bufferPoolHitRate->hint);
        self::assertSame(20.0, $view->tmpDiskTables?->value, 'delta: 10 disk tables out of 50');
        self::assertSame(2.0, $view->threadCacheMiss?->value, 'delta: 2 threads created for 100 connections');
        self::assertSame(1.0, $view->lockWaits?->value, 'delta: 1 waited for 99 immediate');

        self::assertSame(15.0, $view->questionsPerSec->value);
        self::assertSame('q/s', $view->questionsPerSec->unit);
        self::assertSame(5.0, $view->innodbReadsPerSec->value);
        self::assertSame(2.0, $view->innodbWritesPerSec->value);

        self::assertTrue($view->slowQueryLog);
        self::assertSame(1.0, $view->longQueryTime);
        self::assertTrue($view->slowQueriesAvailable);
        self::assertCount(1, $view->slowQueries);
        self::assertSame(300, $view->slowQueries[0]['avg_time_ms']);
        self::assertSame('app', $view->schema);
        self::assertSame(500_000_000, $view->dataLength);
        self::assertSame(120_000_000, $view->indexLength);
        self::assertSame('134217728', $view->variables['innodb_buffer_pool_size']);
    }

    public function testMariaDbBufferPoolUsesTheFreeBytes(): void
    {
        $status = $this->statusPayload();
        $status['Innodb_buffer_pool_bytes_free'] = '16777216';

        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', $this->variablesPayload(), null, 1_000),
        ]), $this->gauges());

        self::assertSame(87.5, $view->bufferPoolUsage?->value, '(128 MB - 16 MB) / 128 MB');
    }

    public function testWithoutDeltaRatiosAreCumulative(): void
    {
        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $this->statusPayload(), null, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', $this->variablesPayload(), null, 1_000),
        ]), $this->gauges());

        self::assertSame(99.0, $view->bufferPoolHitRate?->value);
        self::assertSame('since server start', $view->bufferPoolHitRate->hint);
        self::assertSame(13.33, $view->tmpDiskTables?->value, '20 disk tables out of 150');
        self::assertTrue($view->questionsPerSec->isUnavailable());
        self::assertFalse($view->slowQueriesAvailable);
        self::assertNull($view->slowQueriesError);
    }

    public function testSlowQueriesErrorIsExposed(): void
    {
        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $this->statusPayload(), null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], 'performance_schema table is not readable', 1_000),
        ]), $this->gauges());

        self::assertFalse($view->slowQueriesAvailable);
        self::assertSame('performance_schema table is not readable', $view->slowQueriesError);
        self::assertNull($view->maxConnections, 'no variables collected');
        self::assertFalse($view->connections->available());
    }

    /**
     * @return array<string, string>
     */
    private function statusPayload(): array
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
    private function variablesPayload(): array
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
        ];
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
