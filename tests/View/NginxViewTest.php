<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\NginxView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class NginxViewTest extends TestCase
{
    public function testTheFirstSample(): void
    {
        $view = new NginxView($this->snapshot(), $this->gauges());

        self::assertSame('1.26.2', $view->version);
        self::assertNull($view->versionStatus, 'no EndOfLife given');

        self::assertTrue($view->configAvailable);
        self::assertSame('auto (4 CPUs)', $view->workerProcesses);
        self::assertSame(768, $view->workerConnections);
        self::assertSame(3_072, $view->maxConnections, 'one worker per CPU');
        self::assertSame('/etc/nginx/nginx.conf', $view->configPath);
        self::assertSame(['/etc/nginx/modules-enabled/*.conf', '/etc/nginx/mime.types', '/etc/nginx/conf.d/*.conf', '/etc/nginx/sites-enabled/*'], $view->includes);
        self::assertSame('www-data', $view->user);
        self::assertSame('/var/log/nginx/access.log', $view->accessLog);
        self::assertSame('/var/log/nginx/error.log', $view->errorLog);
        self::assertSame('warn', $view->errorLogLevel);
        self::assertTrue($view->sendfile);
        self::assertTrue($view->tcpNopush);
        self::assertNull($view->tcpNodelay, 'not in the configuration');
        self::assertSame(65, $view->keepaliveTimeout);
        self::assertEquals(new Badge(BadgeStyle::Success, 'On'), $view->gzip);
        self::assertSame('TLSv1.2 TLSv1.3', $view->sslProtocols);
        self::assertFalse($view->sslPreferServerCiphers);
        self::assertEquals(new Badge(BadgeStyle::Success, 'Off'), $view->serverTokens, 'the version stays hidden');

        self::assertSame([12, 1, 3, 8], [$view->active, $view->reading, $view->writing, $view->waiting]);
        self::assertSame(0.4, $view->activeConnections->value, '12 of 3072 connections, rounded to one decimal');
        self::assertSame('12 / 3072', $view->activeConnections->detail);
        self::assertNull($view->activeConnections->hint);
        self::assertSame('Available Capacity', $view->activeConnections->badge?->label);

        self::assertTrue($view->requestsPerSec->isPending());
        self::assertSame(2.233062, $view->requestsPerConnection->value, '41200 requests over 18450 connections');
        self::assertSame('since server start', $view->requestsPerConnection->hint());
        self::assertSame(0, $view->refused);
        self::assertEquals(new Badge(BadgeStyle::Success, 'None'), $view->refusedStatus);
    }

    public function testTheTrafficComesFromTheDelta(): void
    {
        $delta = new Delta(Delta::flatten(Payloads::nginxStatus(18_400, 18_400, 41_000)), Delta::flatten(Payloads::nginxStatus()), 10);

        $view = new NginxView($this->snapshot(delta: $delta), $this->gauges());

        self::assertSame(20.0, $view->requestsPerSec->value, '200 requests in 10 seconds');
        self::assertSame('req/s', $view->requestsPerSec->unit);
        self::assertSame(4.0, $view->requestsPerConnection->value, '200 requests over 50 connections');
        self::assertNull($view->requestsPerConnection->hint());
    }

    public function testConnectionsRefusedSinceTheLastSampleReachedTheLimit(): void
    {
        $delta = new Delta(Delta::flatten(Payloads::nginxStatus(18_400, 18_390)), Delta::flatten(Payloads::nginxStatus(18_450, 18_437)), 10);

        $view = new NginxView($this->snapshot(Payloads::nginxStatus(18_450, 18_437), delta: $delta), $this->gauges());

        self::assertSame(13, $view->refused, 'since start');
        self::assertEquals(new Badge(BadgeStyle::Danger, 'Limit reached'), $view->refusedStatus, '50 accepted, 47 handled since the last sample');
    }

    public function testConnectionsRefusedEarlierOnlyWarn(): void
    {
        $delta = new Delta(Delta::flatten(Payloads::nginxStatus(18_400, 18_387)), Delta::flatten(Payloads::nginxStatus(18_450, 18_437)), 10);

        $view = new NginxView($this->snapshot(Payloads::nginxStatus(18_450, 18_437), delta: $delta), $this->gauges());

        self::assertSame(13, $view->refused);
        self::assertEquals(new Badge(BadgeStyle::Warning, 'Earlier'), $view->refusedStatus, 'none since the last sample: it does not stay red until Nginx restarts');
    }

    public function testRefusedConnectionsWithoutDeltaWarn(): void
    {
        $view = new NginxView($this->snapshot(Payloads::nginxStatus(18_450, 18_437)), $this->gauges());

        self::assertEquals(new Badge(BadgeStyle::Warning, 'Earlier'), $view->refusedStatus);
    }

    public function testAFixedNumberOfWorkers(): void
    {
        $config = Payloads::nginxConfig();
        $config['config']['worker_processes'] = '2';
        unset($config['cpu_count']);

        $view = new NginxView($this->snapshot(config: $config), $this->gauges());

        self::assertSame('2', $view->workerProcesses);
        self::assertSame(1_536, $view->maxConnections);
    }

    public function testWorkersAutoWithoutCpuCountHaveNoLimit(): void
    {
        $config = Payloads::nginxConfig();
        unset($config['cpu_count']);

        $view = new NginxView($this->snapshot(config: $config), $this->gauges());

        self::assertSame('auto', $view->workerProcesses);
        self::assertNull($view->maxConnections);
        self::assertNull($view->activeConnections->value);
        self::assertSame('connection limit unknown', $view->activeConnections->hint);
        self::assertSame(12, $view->active, 'the connections are still counted');
    }

    public function testWithoutNginxTTheConfigurationIsUnavailable(): void
    {
        $config = Payloads::nginxConfig();
        $config['config'] = array_map(static fn(mixed $value): mixed => is_array($value) ? [] : null, $config['config']);

        $view = new NginxView($this->snapshot(config: $config), $this->gauges());

        self::assertFalse($view->configAvailable);
        self::assertSame('1.26.2', $view->version, 'from another command');
        self::assertNull($view->workerProcesses);
        self::assertNull($view->maxConnections);
        self::assertSame([], $view->includes);
        self::assertNull($view->errorLog);
        self::assertNull($view->errorLogLevel);
        self::assertNull($view->gzip);
        self::assertSame('connection limit unknown', $view->activeConnections->hint);
    }

    public function testAFailedStatusKeepsTheVersionAndTheConfiguration(): void
    {
        $snapshot = new ComponentSnapshot(Component::Nginx, [
            'nginx.status' => new CollectorResult('nginx.status', [], 'Cannot read the Nginx status', 1_000),
            'nginx.config' => new CollectorResult('nginx.config', Payloads::nginxConfig(), null, 1_000),
        ]);

        $view = new NginxView($snapshot, $this->gauges());

        self::assertSame('1.26.2', $view->version);
        self::assertTrue($view->configAvailable);
        self::assertSame(3_072, $view->maxConnections);
        self::assertNull($view->active);
        self::assertNull($view->activeConnections->value);
        self::assertNull($view->activeConnections->hint);
        self::assertNull($view->refusedStatus);
    }

    public function testCommandsNotCollectedAreNotAMissingConfiguration(): void
    {
        $snapshot = new ComponentSnapshot(Component::Nginx, ['nginx.status' => new CollectorResult('nginx.status', Payloads::nginxStatus(), null, 1_000)]);

        $view = new NginxView($snapshot, $this->gauges());

        self::assertNull($view->configAvailable, 'nothing says that "nginx -T" failed');
        self::assertSame(12, $view->active);
    }

    public function testDuplicateIncludesAreListedOnce(): void
    {
        $config = Payloads::nginxConfig();
        $config['config']['include'] = ['/etc/nginx/mime.types', 'fastcgi_params', 'fastcgi_params', 'snippets/fastcgi-php.conf', 'fastcgi_params'];

        $view = new NginxView($this->snapshot(config: $config), $this->gauges());

        self::assertSame(['/etc/nginx/mime.types', 'fastcgi_params', 'snippets/fastcgi-php.conf'], $view->includes);
    }

    public function testAnOnOffDirectiveWithAnotherValueIsShownAsWritten(): void
    {
        $config = Payloads::nginxConfig();
        $config['config']['server_tokens'] = 'build';
        $config['config']['error_log'] = 'syslog:server=unix:/dev/log';

        $view = new NginxView($this->snapshot(config: $config), $this->gauges());

        self::assertSame('build', $view->serverTokens);
        self::assertSame('syslog:server=unix:/dev/log', $view->errorLog);
        self::assertNull($view->errorLogLevel);
    }

    public function testTheVersionStatusComesFromTheNginxCycles(): void
    {
        $fetcher = new FixtureUrlFetcher((string) json_encode([['cycle' => '1.26', 'eol' => '2025-04-23', 'support' => '2025-04-23']]));

        $view = new NginxView($this->snapshot(), $this->gauges(), new EndOfLife(new ArrayAdapter(), new MockClock('2026-10-21'), fetcher: $fetcher));

        self::assertSame('End of life', $view->versionStatus?->label);
        self::assertSame('https://endoflife.date/api/nginx.json', $fetcher->url);
    }

    public function testFailedCollectorsAreUnavailable(): void
    {
        $snapshot = new ComponentSnapshot(Component::Nginx, [
            'nginx.status' => new CollectorResult('nginx.status', [], 'boom', 1),
            'nginx.config' => new CollectorResult('nginx.config', [], 'boom', 1),
        ]);

        $view = new NginxView($snapshot, $this->gauges());

        self::assertNull($view->version);
        self::assertNull($view->configAvailable);
        self::assertNull($view->active);
        self::assertNull($view->activeConnections->value);
        self::assertNull($view->activeConnections->detail);
        self::assertNull($view->activeConnections->hint);
        self::assertNull($view->refused);
        self::assertNull($view->refusedStatus);
        self::assertTrue($view->requestsPerSec->isUnavailable());
        self::assertFalse($view->requestsPerSec->isPending(), 'no later sample would bring it');
        self::assertTrue($view->requestsPerConnection->isUnavailable());
    }

    /**
     * @param array<string, mixed>|null $status
     * @param array<string, mixed>|null $config
     */
    private function snapshot(?array $status = null, ?array $config = null, ?Delta $delta = null): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Nginx, [
            'nginx.status' => new CollectorResult('nginx.status', $status ?? Payloads::nginxStatus(), null, 1_000, $delta),
            'nginx.config' => new CollectorResult('nginx.config', $config ?? Payloads::nginxConfig(), null, 1_000),
        ]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
