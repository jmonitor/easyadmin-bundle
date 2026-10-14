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
        ]);
        $system = new ComponentSnapshot(Component::System, ['system' => new CollectorResult('system', ['ram' => ['total' => 1_073_741_824]], null, 1_000)]);

        $html = $this->render('mysql', $snapshot, new MysqlView($snapshot, $this->gauges(), $system));

        self::assertStringContainsString('MySQL Community Server - GPL', $html);
        self::assertStringContainsString('Connection usage', $html);
        self::assertStringContainsString('Current: 30 · Peak: 45', $html);
        self::assertStringContainsString('15.00 <small class="text-muted">q/s</small>', $html);
        self::assertStringContainsString('SELECT * FROM `order`', $html);
        self::assertStringContainsString('The slow query log is disabled on your side.', $html);
        self::assertStringContainsString('slow_query_log_file', $html);
        self::assertStringContainsString('RAM ratio', $html);
        self::assertStringNotContainsString('time_zone</dt>', $html, 'only the keys of the mysql.config help');
        self::assertStringNotContainsString('<html', $html);
    }

    public function testTheMysqlFragmentWithoutSlowQueries(): void
    {
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', Payloads::mysqlStatus(), null, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], 'performance_schema table is not readable', 1_000),
        ]);
        $system = new ComponentSnapshot(Component::System, [], 'This component is not available.');

        $html = $this->render('mysql', $snapshot, new MysqlView($snapshot, $this->gauges(), $system));

        self::assertStringContainsString('Slow queries data are not available.', $html);
        self::assertStringContainsString('performance_schema table is not readable', $html);
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
        self::assertStringNotContainsString('Current:', $html, 'no maximum to draw the peak against');
        self::assertStringContainsString('since server start', $html);
    }

    public function testAFailedRedisCollectorStillRenders(): void
    {
        $snapshot = new ComponentSnapshot(Component::Redis, ['redis' => new CollectorResult('redis', [], 'Redis exception: Connection refused', 1_000)]);

        $html = $this->render('redis', $snapshot, new RedisView($snapshot, $this->gauges()));

        self::assertStringContainsString('Connection refused', $html);
        self::assertStringNotContainsString('Databases', $html);
    }

    public function testTheSystemFragmentShowsTheLoadAverageUnderTheCpuGauge(): void
    {
        $snapshot = new ComponentSnapshot(Component::System, ['system' => new CollectorResult('system', [
            'cpu' => ['cores' => 4, 'load' => 62, 'load1' => 1.25, 'load5' => 1.65, 'load15' => 1.7],
            'hostname' => 'web-1',
            'timezone' => 'Europe/Paris',
        ], null, 1_000)]);

        $html = $this->render('system', $snapshot, new SystemView($snapshot, $this->gauges()));

        self::assertStringContainsString('Load average 1.25', $html);
        self::assertStringContainsString('System informations', $html);
        self::assertStringContainsString('CPU cores', $html);
        self::assertStringNotContainsString('1.65', $html);
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
            'error_log' => '',
        ], null, 1_000)]);

        $html = $this->render('php', $snapshot, new PhpView($snapshot, $this->gauges()));

        self::assertStringContainsString('APCu is disabled.', $html);
        self::assertStringContainsString('PHP-FPM usage not detected.', $html);
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
