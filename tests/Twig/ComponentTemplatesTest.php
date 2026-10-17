<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Twig;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use Jmonitor\EasyAdminBundle\View\MysqlView;
use Jmonitor\EasyAdminBundle\View\PhpView;
use Jmonitor\EasyAdminBundle\View\PostgresqlView;
use Jmonitor\EasyAdminBundle\View\RedisView;
use Jmonitor\EasyAdminBundle\View\SymfonyView;
use Jmonitor\EasyAdminBundle\View\SystemView;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The functional tests cannot reach MySQL and Redis: their fragments are rendered here from
 * fixture payloads, with strict variables on, so a wrong property name in a template fails.
 */
final class ComponentTemplatesTest extends TestCase
{
    private TestKernel $kernel;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel();
        $this->kernel->boot();
        /** @var Environment $twig */
        $twig = $this->kernel->getContainer()->get('test.service_container')->get('twig');
        $this->twig = $twig;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        restore_exception_handler();
    }

    public function testTheMysqlFragmentRenders(): void
    {
        $previous = ['Questions' => '1000', 'Table_locks_waited' => '2', 'Table_locks_immediate' => '998'];
        $status = Payloads::mysqlStatus();
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', $status, null, 1_000, new Delta($previous, Delta::flatten($status), 10)),
            'mysql.variables' => new CollectorResult('mysql.variables', ['slow_query_log' => 'OFF'] + Payloads::mysqlVariables(), null, 1_000),
            'mysql.information_schema' => new CollectorResult('mysql.information_schema', ['schema_name' => 'app', 'data_weight' => ['data_length' => 500_000_000, 'index_length' => 120_000_000]], null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', Payloads::mysqlSlowQueries(), null, 1_000),
            'system.ram_total' => new CollectorResult('system.ram_total', ['total' => 1_073_741_824], null, 1_000),
        ]);

        $html = $this->render('mysql', $snapshot, new MysqlView($snapshot, $this->gauges(), 1_000));

        self::assertStringContainsString('MySQL Community Server - GPL', $html);
        self::assertStringContainsString('Connection usage', $html);
        self::assertStringContainsString('aria-label="Help: Connection usage"', $html, 'help links are named after their visible label');
        self::assertStringContainsString('aria-label="Help: MySQL configuration"', $html);
        self::assertStringContainsString('Current: 30 · Peak: 45', $html);
        self::assertStringContainsString('15.00 <small class="text-muted">q/s</small>', $html);
        self::assertStringContainsString('<code class="jm-query">SELECT * FROM `order`', $html);
        self::assertLessThan(strpos($html, '<span>Total</span>'), strpos($html, '<span>Top slowest queries</span>'), 'the total sits on the right');
        self::assertMatchesRegularExpression('#<div class="card">\s*<div class="card-header[^"]*">\s*<span>Total</span>#', $html, 'the total card keeps its own height');
        self::assertStringContainsString('The slow query log is disabled on your side.', $html);
        self::assertStringContainsString('slow_query_log_file', $html);
        self::assertStringContainsString('RAM ratio', $html);
        self::assertStringContainsString('1 / 100', $html, 'lock waits detail from the delta');
        self::assertStringContainsString('7 / 1100', $html, 'thread cache miss detail since the start');
        self::assertMatchesRegularExpression('#<dt>Date</dt>\s*<dd>\d{4}-\d{2}-\d{2} \d{2}:\d{2}</dd>#', $html);
        self::assertStringNotContainsString('time_zone</dt>', $html, 'only the keys of the mysql.config help');
        self::assertStringNotContainsString('<html', $html);
    }

    public function testTheMysqlFragmentWithoutSlowQueries(): void
    {
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', Payloads::mysqlStatus(), null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], 'performance_schema table is not readable', 1_000),
        ]);

        $html = $this->render('mysql', $snapshot, new MysqlView($snapshot, $this->gauges(), 1_000));

        self::assertStringContainsString('Slow queries data are not available.', $html);
        self::assertStringContainsString('performance_schema table is not readable', $html);
        self::assertStringNotContainsString('RAM ratio', $html, 'MySQL is not on this host');
    }

    public function testThePostgresqlFragmentRenders(): void
    {
        $activity = Payloads::postgresqlActivity();
        $database = Payloads::postgresqlDatabase();
        $activityPrevious = ['database_stats.xact_commit' => 49_900, 'database_stats.xact_rollback' => 490, 'database_stats.blks_read' => 1_990, 'database_stats.blks_hit' => 397_010, 'database_stats.tup_inserted' => 9_900, 'database_stats.tup_updated' => 3_950, 'database_stats.tup_deleted' => 980, 'database_stats.deadlocks' => 2, 'database_stats.temp_files' => 9, 'database_stats.temp_bytes' => 9_437_184];
        $snapshot = new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', $activity, null, 1_000, new Delta($activityPrevious, Delta::flatten($activity), 10)),
            'postgresql.settings' => new CollectorResult('postgresql.settings', Payloads::postgresqlSettings(), null, 1_000),
            'postgresql.database' => new CollectorResult('postgresql.database', $database, null, 1_000, new Delta(['tables.seq_scans' => 4_940, 'tables.idx_scans' => 94_400], Delta::flatten($database), 60)),
            'postgresql.slow_queries' => new CollectorResult('postgresql.slow_queries', Payloads::postgresqlSlowQueries(), null, 1_000),
            'system.ram_total' => new CollectorResult('system.ram_total', ['total' => 1_073_741_824], null, 1_000),
        ]);

        $html = $this->render('postgresql', $snapshot, new PostgresqlView($snapshot, $this->gauges()));

        self::assertStringContainsString('16.4 (Debian 16.4-1.pgdg120+1)', $html);
        self::assertStringContainsString('Connections usage', $html);
        self::assertStringContainsString('aria-label="Help: Connections usage"', $html, 'help links are named after their visible label');
        self::assertStringContainsString('12 / 100', $html);
        self::assertStringContainsString('11.00 <small class="text-muted">tx/s</small>', $html);
        self::assertStringContainsString('Idle in transaction', $html);
        self::assertStringContainsString('<code class="jm-query">SELECT * FROM customer', $html);
        self::assertStringContainsString('99.00%', $html, 'slow query cache hit');
        self::assertStringContainsString('300 ms', $html, 'slow query average');
        self::assertStringContainsString('1 200 ms', $html, 'slow query maximum');
        self::assertStringContainsString('UPDATE account SET balance', $html);
        self::assertStringContainsString('Dead rows ratio', $html);
        self::assertStringContainsString('100 000 / 1 000 000', $html, 'dead rows out of all rows, like the gauge value');
        self::assertStringContainsString('backends of this database', $html);
        self::assertStringContainsString('RAM ratio', $html);
        self::assertStringContainsString('Collector configuration:', $html);
        self::assertStringContainsString('disabled', $html, 'log_min_duration_statement badge');
        self::assertStringNotContainsString('Help: RAM ratio', $html, 'no help page for the RAM ratio');
        self::assertStringNotContainsString('<html', $html);
    }

    public function testThePostgresqlFragmentWithoutSlowQueriesOrBlocking(): void
    {
        $activity = Payloads::postgresqlActivity();
        $activity['sessions']['blocked_queries'] = [];
        $activity['sessions']['oldest_transaction_seconds'] = null;
        $snapshot = new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', $activity, null, 1_000),
            'postgresql.settings' => new CollectorResult('postgresql.settings', Payloads::postgresqlSettings(), null, 1_000),
            'postgresql.database' => new CollectorResult('postgresql.database', Payloads::postgresqlDatabase(), null, 1_000),
        ]);

        $html = $this->render('postgresql', $snapshot, new PostgresqlView($snapshot, $this->gauges()));

        self::assertStringContainsString('Slow queries data are not available.', $html);
        self::assertStringContainsString('No blocking detected.', $html);
        self::assertStringNotContainsString('No active transaction', $html, 'a null oldest transaction means the sub-query failed, not that there is none');
        self::assertStringNotContainsString('Longest running transaction', $html);
        self::assertStringNotContainsString('RAM ratio', $html, 'PostgreSQL is not on this host');
        self::assertStringContainsString('since stats reset', $html, 'no delta: the ratios are cumulated');
        self::assertStringContainsString('Since stats reset', $html, 'the deadlocks total');
        self::assertStringNotContainsString('server start', $html);
    }

    public function testThePostgresqlFragmentRendersWithoutAnyData(): void
    {
        $snapshot = new ComponentSnapshot(Component::Postgresql, [
            'postgresql.activity' => new CollectorResult('postgresql.activity', [], 'PostgreSQL is not accessible', 1_000),
            'postgresql.settings' => new CollectorResult('postgresql.settings', [], 'PostgreSQL is not accessible', 1_000),
            'postgresql.database' => new CollectorResult('postgresql.database', [], 'pg_stat_user_tables is not accessible', 1_000),
            'postgresql.slow_queries' => new CollectorResult('postgresql.slow_queries', [], 'pg_stat_statements extension is not created. Run: CREATE EXTENSION pg_stat_statements', 1_000),
        ]);

        $html = $this->render('postgresql', $snapshot, new PostgresqlView($snapshot, $this->gauges()));

        self::assertStringContainsString('PostgreSQL is not accessible', $html);
        self::assertStringContainsString('CREATE EXTENSION pg_stat_statements', $html);
        self::assertStringContainsString('n/a', $html);
        self::assertStringNotContainsString('No blocking detected', $html, 'nothing was collected, so nothing is known');
        self::assertStringNotContainsString('No active transaction', $html);
    }

    public function testTheRedisFragmentRendersWithAMemoryLimit(): void
    {
        $payload = Payloads::redis();
        $payload['memory']['max_memory'] = 2_000_000_000;
        $snapshot = new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', $payload, null, 1_000)]);

        $html = $this->render('redis', $snapshot, new RedisView($snapshot, $this->gauges()));

        self::assertStringContainsString('Redis informations', $html);
        self::assertStringContainsString('Memory usage', $html);
        self::assertStringContainsString('Current: 1.4 GB · Peak: 1.5 GB', $html);
        self::assertStringContainsString('least recently used keys from the entire dataset', $html);
        self::assertStringContainsString('Db0', $html);
        self::assertStringContainsString('1h 0m', $html, 'average TTL in seconds');
    }

    public function testTheRedisFragmentRendersWithoutAMemoryLimit(): void
    {
        $snapshot = new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', Payloads::redis(), null, 1_000)]);

        $html = $this->render('redis', $snapshot, new RedisView($snapshot, $this->gauges()));

        self::assertStringContainsString('Unlimited', $html);
        self::assertStringContainsString('Memory peak', $html);
        self::assertStringContainsString('<div class="jm-stat">1.5 GB</div>', $html, 'the peak, without a maximum to draw it against');
        self::assertStringContainsString('Current: 1.4 GB', $html);
        self::assertStringContainsString('since server start', $html);
    }

    public function testAFailedRedisCollectorStillRenders(): void
    {
        $snapshot = new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', [], 'Redis exception: Connection refused', 1_000)]);

        $html = $this->render('redis', $snapshot, new RedisView($snapshot, $this->gauges()));

        self::assertStringContainsString('Connection refused', $html);
        self::assertStringNotContainsString('Databases', $html);
    }

    public function testTheSystemFragmentShowsTheCoresUnderTheCpuGaugeAndTheLoadAverageAsTiles(): void
    {
        $snapshot = new ComponentSnapshot(Component::System, ['system' => new CollectorResult('system', [
            'cpu' => ['cores' => 4, 'load' => 62, 'load1' => 1.25, 'load5' => 1.65, 'load15' => 1.7],
            'hostname' => 'web-1',
            'timezone' => 'Europe/Paris',
        ], null, 1_000)]);

        $html = $this->render('system', $snapshot, new SystemView($snapshot, $this->gauges()));

        self::assertStringContainsString('4 cores', $html);
        self::assertStringNotContainsString('CPU cores', $html);
        self::assertStringContainsString('System informations', $html);
        self::assertMatchesRegularExpression('#Hostname.*OS.*Server time.*Timezone.*Uptime.*Load average#s', $html);
        self::assertStringNotContainsString('<hr>', $html);
        self::assertSame(3, substr_count($html, 'class="jm-tile"'), 'the 1, 5 and 15-minute load average tiles');
        self::assertStringContainsString('1.65', $html);
        self::assertStringContainsString('15 min', $html);
    }

    public function testTheSystemFragmentHidesAnUnknownLoadAverage(): void
    {
        $snapshot = new ComponentSnapshot(Component::System, ['system' => new CollectorResult('system', ['hostname' => 'web-1'], null, 1_000)]);

        $html = $this->render('system', $snapshot, new SystemView($snapshot, $this->gauges()));

        self::assertStringContainsString('web-1', $html);
        self::assertStringNotContainsString('jm-tile', $html);
    }

    public function testThePhpFragmentRendersEverySection(): void
    {
        $delta = new Delta(Delta::flatten(['fpm' => ['accepted-conn' => 4950]]), Delta::flatten(['fpm' => ['accepted-conn' => 5000]]), 10);
        $snapshot = new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', Payloads::php(), null, 1_000, $delta)]);

        $html = $this->render('php', $snapshot, new PhpView($snapshot, $this->gauges()));

        self::assertStringContainsString('5.00 <small class="text-muted">req/s</small>', $html);
        self::assertStringContainsString('Execution mode (SAPI)', $html);
        self::assertStringContainsString('<li>/etc/php/8.4/fpm/php.ini</li>', $html);
        self::assertStringContainsString('data-bs-target="#jm-php-extensions"', $html);
        self::assertStringContainsString('FPM informations', $html);
        self::assertStringContainsString('Max children reached', $html);
        self::assertStringNotContainsString('interned_strings_buffer', $html, 'only the keys of the php.opcache_config help');
    }

    public function testThePhpFragmentWithoutOpcacheApcuOrFpm(): void
    {
        $snapshot = new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', [
            'version' => '8.5.8',
            'sapi_name' => 'frankenphp',
            'memory_limit' => '-1',
            'max_execution_time' => 0,
            'error_log' => '',
        ], null, 1_000)]);

        $html = $this->render('php', $snapshot, new PhpView($snapshot, $this->gauges()));

        self::assertStringContainsString('APCu is disabled.', $html);
        self::assertStringContainsString('PHP-FPM usage not detected.', $html);
        self::assertMatchesRegularExpression('#memory_limit</dt>\s*<dd[^>]*><span class="badge[^"]*">Unlimited</span>#', $html);
        self::assertMatchesRegularExpression('#max_execution_time</dt>\s*<dd[^>]*><span class="badge[^"]*">Unlimited</span>#', $html);
        self::assertMatchesRegularExpression('#error_log</dt>\s*<dd[^>]*><span class="text-muted">—</span>#', $html);
    }

    public function testTheSymfonyFragmentRenders(): void
    {
        $snapshot = new ComponentSnapshot(Component::Symfony, ['symfony' => new CollectorResult('symfony', Payloads::symfony(), null, 1_000)]);
        $php = new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', Payloads::php(), null, 1_000)]);

        $html = $this->render('symfony', $snapshot, new SymfonyView($snapshot, $php));

        self::assertStringContainsString('PROD', $html);
        self::assertStringContainsString('Debug: disabled', $html);
        self::assertStringContainsString('realpath_cache_size', $html);
        self::assertStringContainsString('Outdated', $html);
        self::assertStringContainsString('Uncountable', $html);
        self::assertStringContainsString('Scheduler - Next task', $html);
        self::assertStringContainsString('0-10 second jitters', $html);
        self::assertStringContainsString('Every 1 hour', $html);
    }

    public function testTheSymfonyFragmentExplainsAFailedCollect(): void
    {
        $snapshot = new ComponentSnapshot(Component::Symfony, ['symfony' => new CollectorResult('symfony', ['env' => 'prod', 'debug' => false, 'bundles' => [], 'components' => [], 'errors' => [
            'flex_recipes' => 'composer recipes failed (exit code: 127): sh: 1: exec: composer: not found',
            'messenger' => 'messenger:stats command failed (exit code: 1): The "--format" option does not exist.',
        ]], null, 1_000)]);
        $php = new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', Payloads::php(), null, 1_000)]);

        $html = $this->render('symfony', $snapshot, new SymfonyView($snapshot, $php));

        self::assertStringContainsString('sh: 1: exec: composer: not found', $html);
        self::assertStringContainsString('<code>flex: false</code>', $html);
        self::assertStringContainsString('The &quot;--format&quot; option does not exist.', $html);
        self::assertStringContainsString('<code>messenger: false</code>', $html);
        self::assertStringContainsString('No tasks', $html);
    }

    public function testTheSymfonyFragmentHidesDisabledComponents(): void
    {
        $snapshot = new ComponentSnapshot(Component::Symfony, ['symfony' => new CollectorResult('symfony', Payloads::symfony(), null, 1_000)]);
        $php = new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', Payloads::php(), null, 1_000)]);

        $html = $this->render('symfony', $snapshot, new SymfonyView($snapshot, $php, messengerEnabled: false, schedulerEnabled: false, flexEnabled: false));

        self::assertStringNotContainsString('Flex recipes', $html);
        self::assertStringNotContainsString('Messenger', $html);
        self::assertStringNotContainsString('Scheduler - Next task', $html);
        self::assertStringNotContainsString('<h2 class="h5 mt-4 mb-2">Components</h2>', $html);
    }

    private function render(string $component, ComponentSnapshot $snapshot, object $view): string
    {
        return $this->twig->render(sprintf('@JmonitorEasyAdmin/component/%s.html.twig', $component), [
            'components' => [Component::from($component)],
            'current' => Component::from($component),
            'polling_interval' => 10,
            'routes' => ['index' => 'admin_jmonitor_index', 'component' => 'admin_jmonitor_component', 'fragment' => 'admin_jmonitor_fragment', 'refresh' => 'admin_jmonitor_refresh'],
            'snapshot' => $snapshot,
            'view' => $view,
            'fragment' => true,
        ]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
