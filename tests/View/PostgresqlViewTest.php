<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\PostgresqlView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlViewTest extends TestCase
{
    private const ACTIVITY_PREVIOUS = ['database_stats.xact_commit' => 49_900, 'database_stats.xact_rollback' => 490, 'database_stats.blks_read' => 1_990, 'database_stats.blks_hit' => 397_010, 'database_stats.tup_inserted' => 9_900, 'database_stats.tup_updated' => 3_950, 'database_stats.tup_deleted' => 980, 'database_stats.deadlocks' => 2, 'database_stats.temp_files' => 9, 'database_stats.temp_bytes' => 9_437_184];
    private const DATABASE_PREVIOUS = ['tables.seq_scans' => 4_940, 'tables.idx_scans' => 94_400];

    public function testGaugesRatesAndFacts(): void
    {
        $view = $this->view(withDeltas: true, withRam: true);

        self::assertSame('16.4 (Debian 16.4-1.pgdg120+1)', $view->version);
        self::assertSame('16', $view->majorVersion);
        self::assertTrue($view->activityAvailable);
        self::assertSame(12, $view->numBackends);
        self::assertSame(100, $view->maxConnections);
        self::assertSame(12.0, $view->connectionsUsage->value);
        self::assertSame(BadgeStyle::Success, $view->connectionsUsage->badge?->style);
        self::assertSame('backends of this database', $view->connectionsUsage->hint);
        self::assertSame(3, $view->activeConnections);
        self::assertSame(8, $view->idleConnections);
        self::assertSame(1, $view->idleInTransactionConnections);

        self::assertSame(11.0, $view->transactionsPerSec->value);
        self::assertSame('tx/s', $view->transactionsPerSec->unit);
        self::assertSame(10.0, $view->commitsPerSec->value);
        self::assertSame(1.0, $view->rollbacksPerSec->value);
        self::assertSame(9.09, $view->rollbackRatio->value, 'delta: 10 rollbacks out of 110 transactions');
        self::assertSame(BadgeStyle::Warning, $view->rollbackRatio->badge?->style);
        self::assertSame(99.0, $view->cacheHitRatio->value, 'delta: 990 hits, 10 reads');
        self::assertSame(BadgeStyle::Success, $view->cacheHitRatio->badge?->style);
        self::assertSame('990 / 1000', $view->cacheHitRatio->detail);
        self::assertNull($view->cacheHitRatio->hint);

        self::assertSame(1.0, $view->diskReadsPerSec->value);
        self::assertSame('blocks/s', $view->diskReadsPerSec->unit);
        self::assertSame(0.1, $view->tempFilesPerSec->value);
        self::assertSame(104_857.6, $view->tempBytesPerSec->value);
        self::assertSame(10.0, $view->rowsInsertedPerSec->value);
        self::assertSame(5.0, $view->rowsUpdatedPerSec->value);
        self::assertSame(2.0, $view->rowsDeletedPerSec->value);
        self::assertSame('rows/s', $view->rowsDeletedPerSec->unit);
        self::assertSame(0.0, $view->deadlocksPerSec->value);
        self::assertSame(2, $view->deadlocksTotal);

        self::assertSame(900_000, $view->liveTuples);
        self::assertSame(100_000, $view->deadTuples);
        self::assertSame(10.0, $view->deadTupleRatio->value);
        self::assertSame(BadgeStyle::Warning, $view->deadTupleRatio->badge?->style);
        self::assertSame(90.91, $view->indexUsageRatio->value, 'delta: 600 index scans, 60 sequential');
        self::assertSame(BadgeStyle::Warning, $view->indexUsageRatio->badge?->style);
        self::assertSame(1.0, $view->seqScansPerSec->value);
        self::assertSame(10.0, $view->idxScansPerSec->value);
        self::assertSame('scans/s', $view->idxScansPerSec->unit);

        self::assertSame(1, $view->blockedCount);
        self::assertSame(7, $view->maxWaitSeconds);
        self::assertSame(42, $view->oldestTransactionSeconds);
        self::assertSame(1, $view->idleInTransactionCount);
        self::assertSame(15, $view->oldestIdleInTransactionSeconds);
        self::assertCount(1, $view->blockedQueries);
        self::assertSame('idle in transaction', $view->blockedQueries[0]['blocking_state']);

        self::assertTrue($view->slowQueriesAvailable);
        self::assertCount(1, $view->slowQueries);
        self::assertSame(99.0, $view->slowQueries[0]['cache_hit']);
        self::assertSame(0.0, $view->slowQueriesMinAvgTimeMs);
        self::assertSame(1, $view->slowQueriesMinCalls);
        self::assertSame('avg', $view->slowQueriesOrderBy);

        self::assertSame(734_003_200, $view->dbSize);
        self::assertSame(620_000_000, $view->tablesTotalSize);
        self::assertSame(120_000_000, $view->indexesSize);
        self::assertSame(42, $view->tableCount);
        self::assertSame('public', $view->schema);

        self::assertSame(134_217_728, $view->sharedBuffers);
        self::assertSame(1_073_741_824, $view->ramTotal);
        self::assertSame(12.5, $view->ramRatio?->value);
        self::assertSame('Small', $view->ramRatio->badge?->label);

        self::assertEquals(new Badge(BadgeStyle::Success, 'on'), $view->autovacuum);
        self::assertEquals(new Badge(BadgeStyle::Success, 'on'), $view->trackCounts);
        self::assertEquals(new Badge(BadgeStyle::Warning, 'disabled'), $view->logMinDurationStatement);
        self::assertSame('replica', $view->setting('wal_level'));
        self::assertNull($view->setting('unknown'));
    }

    public function testRatiosFallBackToSinceStartWithoutDelta(): void
    {
        $view = $this->view(withDeltas: false, withRam: false);

        self::assertSame(0.99, $view->rollbackRatio->value);
        self::assertSame('since stats reset', $view->rollbackRatio->hint, 'pg_stat_database survives a restart, pg_stat_reset() clears it');
        self::assertSame(99.5, $view->cacheHitRatio->value);
        self::assertSame('since stats reset', $view->cacheHitRatio->hint);
        self::assertSame(95.0, $view->indexUsageRatio->value);
        self::assertSame('since stats reset', $view->indexUsageRatio->hint);
        self::assertTrue($view->transactionsPerSec->isUnavailable());
        self::assertTrue($view->seqScansPerSec->isUnavailable());
        self::assertNull($view->ramRatio);
    }

    public function testACounterThatWentDownGivesNoRate(): void
    {
        $activity = Payloads::postgresqlActivity();
        $delta = new Delta(['database_stats.xact_commit' => 60_000] + self::ACTIVITY_PREVIOUS, Delta::flatten($activity), 10);
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', $activity, null, 1_000, $delta),
        ]), $this->gauges());

        self::assertTrue($view->commitsPerSec->isUnavailable());
        self::assertTrue($view->transactionsPerSec->isUnavailable());
        self::assertSame(1.0, $view->rollbacksPerSec->value);
        self::assertSame('since stats reset', $view->rollbackRatio->hint);
    }

    public function testEverythingIsNullSafeWhenTheCollectorsFailed(): void
    {
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', [], 'PostgreSQL is not accessible', 1_000),
            'postgresql.settings' => new CollectorResult('postgresql.settings', [], 'PostgreSQL is not accessible', 1_000),
            'postgresql.database' => new CollectorResult('postgresql.database', [], 'pg_stat_user_tables is not accessible', 1_000),
            'postgresql.slow_queries' => new CollectorResult('postgresql.slow_queries', [], 'pg_stat_statements extension is not created', 1_000),
        ]), $this->gauges());

        self::assertNull($view->version);
        self::assertNull($view->majorVersion);
        self::assertFalse($view->activityAvailable);
        self::assertFalse($view->connectionsUsage->available());
        self::assertNull($view->activeConnections);
        self::assertTrue($view->transactionsPerSec->isUnavailable());
        self::assertFalse($view->cacheHitRatio->available());
        self::assertNull($view->deadTupleRatio->value);
        self::assertFalse($view->indexUsageRatio->available());
        self::assertNull($view->blockedCount);
        self::assertSame([], $view->blockedQueries);
        self::assertFalse($view->slowQueriesAvailable);
        self::assertSame([], $view->slowQueries);
        self::assertNull($view->autovacuum);
        self::assertNull($view->trackCounts);
        self::assertNull($view->logMinDurationStatement);
        self::assertNull($view->ramRatio);
    }

    public function testMissingConnectionStatesAreZeroWhenCollected(): void
    {
        $activity = ['connections' => ['idle' => 2]] + Payloads::postgresqlActivity();
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', $activity, null, 1_000),
        ]), $this->gauges());

        self::assertSame(0, $view->activeConnections);
        self::assertSame(2, $view->idleConnections);
        self::assertSame(0, $view->idleInTransactionConnections);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function versions(): iterable
    {
        yield 'short' => ['16.4', '16'];
        yield 'with build' => ['16.4 (Debian 16.4-1.pgdg120+1)', '16'];
        yield 'legacy' => ['9.6.24', '9'];
        yield 'missing' => [null, null];
    }

    #[DataProvider('versions')]
    public function testMajorVersion(?string $version, ?string $major): void
    {
        $settings = $version === null ? [] : ['server_version' => $version];
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.settings' => new CollectorResult('postgresql.settings', $settings, null, 1_000),
        ]), $this->gauges());

        self::assertSame($major, $view->majorVersion);
    }

    public function testTuningBadges(): void
    {
        $settings = ['autovacuum' => 'off', 'track_counts' => 'off', 'log_min_duration_statement' => '0'] + Payloads::postgresqlSettings();
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.settings' => new CollectorResult('postgresql.settings', $settings, null, 1_000),
        ]), $this->gauges());

        self::assertEquals(new Badge(BadgeStyle::Danger, 'off'), $view->autovacuum);
        self::assertEquals(new Badge(BadgeStyle::Warning, 'off'), $view->trackCounts);
        self::assertSame('0 ms', $view->logMinDurationStatement);

        $logged = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.settings' => new CollectorResult('postgresql.settings', ['log_min_duration_statement' => '1000'], null, 1_000),
        ]), $this->gauges());

        self::assertSame('1000 ms', $logged->logMinDurationStatement);
    }

    public function testSlowQueryWithoutBlockReadsHasNoCacheHit(): void
    {
        $slow = Payloads::postgresqlSlowQueries();
        $slow['slow_queries'][0]['shared_blks_hit'] = 0;
        $slow['slow_queries'][0]['shared_blks_read'] = 0;
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.slow_queries' => new CollectorResult('postgresql.slow_queries', $slow, null, 1_000),
        ]), $this->gauges());

        self::assertNull($view->slowQueries[0]['cache_hit']);
        self::assertSame('SELECT * FROM customer WHERE email = $1', $view->slowQueries[0]['query_sample']);
    }

    public function testABlockedRowWithoutQueryTextIsKept(): void
    {
        $activity = Payloads::postgresqlActivity();
        $activity['sessions']['blocked_queries'][0]['blocked_query_sample'] = null;
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', $activity, null, 1_000),
        ]), $this->gauges());

        self::assertCount(1, $view->blockedQueries, 'the collector counted it in blocked_count');
        self::assertNull($view->blockedQueries[0]['blocked_query_sample']);
    }

    public function testEmptySchema(): void
    {
        $database = Payloads::postgresqlDatabase();
        $database['tables'] = ['table_count' => 0, 'live_tuples' => 0, 'dead_tuples' => 0, 'seq_scans' => 0, 'idx_scans' => 0, 'total_size' => 0, 'indexes_size' => 0];
        $view = new PostgresqlView(new ComponentSnapshot(Component::Postgresql, [
            'postgresql.database' => new CollectorResult('postgresql.database', $database, null, 1_000),
        ]), $this->gauges());

        self::assertNull($view->deadTupleRatio->value);
        self::assertFalse($view->indexUsageRatio->available());
        self::assertSame(0, $view->tableCount);
    }

    private function view(bool $withDeltas, bool $withRam): PostgresqlView
    {
        $activity = Payloads::postgresqlActivity();
        $database = Payloads::postgresqlDatabase();
        $results = [
            'postgresql.activity' => new CollectorResult('postgresql.activity', $activity, null, 1_000, $withDeltas ? new Delta(self::ACTIVITY_PREVIOUS, Delta::flatten($activity), 10) : null),
            'postgresql.settings' => new CollectorResult('postgresql.settings', Payloads::postgresqlSettings(), null, 1_000),
            'postgresql.database' => new CollectorResult('postgresql.database', $database, null, 1_000, $withDeltas ? new Delta(self::DATABASE_PREVIOUS, Delta::flatten($database), 60) : null),
            'postgresql.slow_queries' => new CollectorResult('postgresql.slow_queries', Payloads::postgresqlSlowQueries(), null, 1_000),
        ];

        if ($withRam) {
            $results['system.ram_total'] = new CollectorResult('system.ram_total', ['total' => 1_073_741_824], null, 1_000);
        }

        return new PostgresqlView(new ComponentSnapshot(Component::Postgresql, $results), $this->gauges());
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
