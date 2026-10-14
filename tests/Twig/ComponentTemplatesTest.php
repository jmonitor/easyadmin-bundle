<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Twig;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;
use Jmonitor\EasyAdminBundle\View\MysqlView;
use Jmonitor\EasyAdminBundle\View\RedisView;
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
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', ['Uptime' => '86400', 'Threads_connected' => '30', 'Threads_running' => '3', 'Threads_created' => '7', 'Connections' => '1100', 'Questions' => '1150', 'Created_tmp_tables' => '150', 'Created_tmp_disk_tables' => '20', 'Max_used_connections' => '45', 'Slow_queries' => '12', 'Innodb_buffer_pool_read_requests' => '110000', 'Innodb_buffer_pool_reads' => '1100', 'Innodb_buffer_pool_pages_total' => '8192', 'Innodb_buffer_pool_pages_free' => '1024', 'Table_locks_waited' => '3', 'Table_locks_immediate' => '1097'], null, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', ['version' => '8.4.0', 'version_comment' => 'MySQL', 'max_connections' => '100', 'innodb_buffer_pool_size' => '134217728', 'slow_query_log' => 'OFF', 'long_query_time' => '1.000000'], null, 1_000),
            'mysql.information_schema' => new CollectorResult('mysql.information_schema', ['schema_name' => 'app', 'data_weight' => ['data_length' => 500_000_000, 'index_length' => 120_000_000]], null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', ['slow_queries' => [['query_sample' => 'SELECT 1', 'exec_count' => 120, 'total_time_ms' => 36000, 'avg_time_ms' => 300, 'max_time_ms' => 1200]]], null, 1_000),
        ]);

        $html = $this->render('mysql', $snapshot, new MysqlView($snapshot, $this->gauges()));

        self::assertStringContainsString('8.4.0', $html);
        self::assertStringContainsString('Connections usage', $html);
        self::assertStringContainsString('SELECT 1', $html);
        self::assertStringContainsString('slow query log is disabled', $html);
        self::assertStringContainsString('innodb_buffer_pool_size', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    public function testTheRedisFragmentRendersWithoutAMemoryLimit(): void
    {
        $snapshot = new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [
                'server' => ['version' => '7.2.4', 'mode' => 'standalone', 'port' => 6379, 'uptime' => 360_000],
                'clients' => ['connected' => 12],
                'memory' => ['used' => 1_500_000_000, 'used_rss' => 1_550_000_000, 'used_peak' => 1_600_000_000, 'max_memory' => 0, 'max_memory_policy' => 'noeviction'],
                'persistence' => ['rdb_last_save_time' => 1_760_000_000, 'rdb_changes_since_last_save' => 42, 'rdb_last_bgsave_status' => 'ok', 'aof_enabled' => 0, 'aof_current_size' => 0],
                'stats' => ['instantaneous_ops_per_sec' => 1234, 'keyspace_hits' => 9950, 'keyspace_misses' => 1050, 'evicted_keys' => 7],
                'replication' => ['role' => 'master', 'connected_slaves' => 0],
                'databases' => ['db0' => ['keys' => 1500, 'expires' => 1200, 'avg_ttl' => 3_600_000]],
                'config' => ['save' => '3600 1'],
            ], null, 1_000),
        ]);

        $html = $this->render('redis', $snapshot, new RedisView($snapshot, $this->gauges()));

        self::assertStringContainsString('7.2.4', $html);
        self::assertStringContainsString('maxmemory', $html, 'no limit: the memory card replaces the gauge');
        self::assertStringContainsString('Hit rate', $html);
        self::assertStringContainsString('since server start', $html);
        self::assertStringContainsString('db0', $html);
        self::assertStringContainsString('1h 0m', $html, 'average TTL in seconds');
    }

    public function testAFailedRedisCollectorStillRenders(): void
    {
        $snapshot = new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', [], 'Redis exception: Connection refused', 1_000)]);

        $html = $this->render('redis', $snapshot, new RedisView($snapshot, $this->gauges()));

        self::assertStringContainsString('Connection refused', $html);
        self::assertStringContainsString('No keys', $html);
    }

    public function testNoHelpLinkWhenTheHelpIsDisabled(): void
    {
        $kernel = new TestKernel(['help_url' => false]);
        $kernel->boot();

        try {
            /** @var Environment $twig */
            $twig = $kernel->getContainer()->get('test.service_container')->get('twig');

            self::assertSame('', trim($twig->render('@JmonitorEasyAdmin/_help.html.twig', ['metric' => 'php.version'])));
        } finally {
            $kernel->shutdown();
        }
    }

    private function render(string $component, ComponentSnapshot $snapshot, object $view): string
    {
        return $this->twig->render(sprintf('@JmonitorEasyAdmin/component/%s.html.twig', $component), [
            'components' => [Component::from($component)],
            'current' => Component::from($component),
            'polling_interval' => 10,
            'routes' => ['component' => 'admin_jmonitor_component', 'fragment' => 'admin_jmonitor_fragment', 'refresh' => 'admin_jmonitor_refresh'],
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
