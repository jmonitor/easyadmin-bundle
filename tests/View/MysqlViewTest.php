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
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\MysqlView;
use PHPUnit\Framework\TestCase;

final class MysqlViewTest extends TestCase
{
    public function testGaugesRatesAndFacts(): void
    {
        $previous = ['Questions' => '1000', 'Innodb_data_reads' => '500', 'Innodb_data_writes' => '200', 'Innodb_buffer_pool_read_requests' => '100000', 'Innodb_buffer_pool_reads' => '1000', 'Created_tmp_disk_tables' => '10', 'Created_tmp_tables' => '100', 'Threads_created' => '5', 'Connections' => '1000', 'Table_locks_waited' => '2', 'Table_locks_immediate' => '998'];
        $status = Payloads::mysqlStatus();
        $delta = new Delta($previous, Delta::flatten($status), 10);

        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000, $delta),
            'mysql.variables' => new CollectorResult('mysql.variables', Payloads::mysqlVariables(), null, 1_000),
            'mysql.information_schema' => new CollectorResult('mysql.information_schema', ['schema_name' => 'app', 'data_weight' => ['data_length' => 500_000_000, 'index_length' => 120_000_000]], null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', Payloads::mysqlSlowQueries(), null, 1_000),
            'system.ram_total' => new CollectorResult('system.ram_total', ['total' => 1_073_741_824], null, 1_000),
        ]), $this->gauges());

        self::assertTrue($view->available);
        self::assertSame('8.4.0', $view->version);
        self::assertSame('MySQL Community Server - GPL', $view->versionComment);
        self::assertSame('SYSTEM', $view->timeZone);
        self::assertSame('UTC', $view->systemTimeZone);
        self::assertSame(86_400, $view->uptime);
        self::assertSame('/var/lib/mysql/slow.log', $view->variable('slow_query_log_file'));
        self::assertNull($view->variable('unknown'));

        self::assertSame(30.0, $view->connectionsUsage->value);
        self::assertSame(BadgeStyle::Success, $view->connectionsUsage->badge?->style);
        self::assertSame(45, $view->maxUsedConnections);
        self::assertSame(45.0, $view->maxConnectionsReached?->peakPercent());
        self::assertSame(15.0, $view->queriesPerSec->value);
        self::assertSame('q/s', $view->queriesPerSec->unit);

        self::assertTrue($view->slowQueriesAvailable);
        self::assertFalse($view->slowQueryLogDisabled);
        self::assertSame(12, $view->slowQueriesCount);
        self::assertCount(1, $view->slowQueries);
        self::assertSame(0, $view->slowQueriesMinAvgTimeMs);
        self::assertSame(1, $view->slowQueriesMinExecCount);
        self::assertSame('avg', $view->slowQueriesOrderBy);

        self::assertSame(88.0, $view->bufferPoolUsage->value, 'MySQL: pages used out of pages total');
        self::assertSame(134_217_728, $view->bufferPoolTotal, '8192 pages of 16 KB');
        self::assertSame(99.0, $view->bufferPoolHitRate->value, 'delta: 10 000 requests, 100 disk reads');
        self::assertNull($view->bufferPoolHitRate->hint);
        self::assertSame(13.0, $view->ramRatio->value, '128 MB of buffer pool for 1 GB of RAM');
        self::assertSame(5.0, $view->innodbReadsPerSec->value);
        self::assertSame(2.0, $view->innodbWritesPerSec->value);

        self::assertSame(0.1, $view->tableLocksWaitedPerSec->value);
        self::assertSame(1.0, $view->tableLocksWaitsPercent->value, 'delta: 1 waited for 99 immediate');
        self::assertSame('1 / 100', $view->tableLocksWaitsPercent->detail);
        self::assertSame(1, $view->tableLocksWaitedDelta);
        self::assertSame(100, $view->tableLocksTotalDelta);
        self::assertSame(1.0, $view->tableLocksWaitsPercentDelta);
        self::assertSame(0.27, $view->tableLocksWaitsPercentSinceStart, '3 waited out of 1 100');
        self::assertSame(3, $view->threadsRunning);
        self::assertSame(2.0, $view->threadCacheMiss->value, 'delta: 2 threads created for 100 connections');
        self::assertSame('2 / 100', $view->threadCacheMiss->detail);
        self::assertNull($view->threadCacheMiss->hint);
        self::assertSame(20.0, $view->tmpTables->value, 'delta: 10 disk tables out of 50');
        self::assertSame('10 / 50', $view->tmpTables->detail);
        self::assertNull($view->tmpTables->hint);
        self::assertSame(1.0, $view->longQueryTime);

        self::assertSame(500_000_000, $view->dataLength);
        self::assertSame(620_000_000, $view->totalLength);
    }

    public function testMariaDbBufferPoolUsesTheFreeBytes(): void
    {
        $status = Payloads::mysqlStatus();
        $status['Innodb_buffer_pool_bytes_free'] = '16777216';

        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', Payloads::mysqlVariables(), null, 1_000),
        ]), $this->gauges());

        self::assertSame(88.0, $view->bufferPoolUsage->value, '(128 MB - 16 MB) / 128 MB');
        self::assertSame(117_440_512, $view->bufferPoolUsed);
        self::assertNull($view->ramRatio, 'MySQL is not on this host');
    }

    public function testWithoutDeltaRatiosAreCumulative(): void
    {
        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', Payloads::mysqlStatus(), null, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', ['slow_query_log' => 'OFF'] + Payloads::mysqlVariables(), null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', Payloads::mysqlSlowQueries(), null, 1_000),
        ]), $this->gauges());

        self::assertSame(99.0, $view->bufferPoolHitRate->value);
        self::assertSame('since server start', $view->bufferPoolHitRate->hint);
        self::assertTrue($view->queriesPerSec->isUnavailable());
        self::assertNull($view->tableLocksWaitsPercentDelta);
        self::assertSame(0.6, $view->threadCacheMiss->value, '7 threads created for 1 100 connections');
        self::assertSame('7 / 1100', $view->threadCacheMiss->detail);
        self::assertSame('since server start', $view->threadCacheMiss->hint);
        self::assertSame(13.33, $view->tmpTables->value, '20 disk tables out of 150');
        self::assertSame('20 / 150', $view->tmpTables->detail);
        self::assertSame('since server start', $view->tmpTables->hint);
        self::assertTrue($view->slowQueryLogDisabled);
    }

    public function testWithoutLockTrafficTheGaugeFallsBackOnTheCountersSinceTheStart(): void
    {
        $status = Payloads::mysqlStatus();
        $delta = new Delta(['Table_locks_waited' => '3', 'Table_locks_immediate' => '1097'], Delta::flatten($status), 10);

        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000, $delta),
        ]), $this->gauges());

        self::assertSame(0, $view->tableLocksWaitedDelta);
        self::assertSame(0, $view->tableLocksTotalDelta);
        self::assertNull($view->tableLocksWaitsPercentDelta);
        self::assertSame(0.27, $view->tableLocksWaitsPercent->value);
        self::assertSame('3 / 1100', $view->tableLocksWaitsPercent->detail, 'the same counters as the ratio');
        self::assertSame('since server start', $view->tableLocksWaitsPercent->hint);
    }

    public function testEveryLockThatWaitedGivesAFullRatioSinceTheStart(): void
    {
        $status = ['Table_locks_waited' => '12', 'Table_locks_immediate' => '0'] + Payloads::mysqlStatus();

        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000),
        ]), $this->gauges());

        self::assertSame(100.0, $view->tableLocksWaitsPercentSinceStart);
    }

    public function testFailedSlowQueriesAreUnavailable(): void
    {
        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', Payloads::mysqlStatus(), null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], 'performance_schema table is not readable', 1_000),
        ]), $this->gauges());

        self::assertFalse($view->slowQueriesAvailable);
        self::assertFalse($view->slowQueryLogDisabled, 'nothing to warn about without slow queries');
        self::assertNull($view->maxConnections, 'no variables collected');
        self::assertFalse($view->connectionsUsage->available());
        self::assertNull($view->maxConnectionsReached);
    }

    public function testAnUnknownHostRamGivesAnEmptyGauge(): void
    {
        $view = new MysqlView(new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', Payloads::mysqlStatus(), null, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', Payloads::mysqlVariables(), null, 1_000),
            'system.ram_total' => new CollectorResult('system.ram_total', [], null, 1_000),
        ]), $this->gauges());

        self::assertNotNull($view->ramRatio);
        self::assertFalse($view->ramRatio->available());
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
