<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\ApacheView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ApacheViewTest extends TestCase
{
    public function testTheFirstSample(): void
    {
        $view = new ApacheView($this->snapshot(Payloads::apache()), $this->gauges());

        self::assertSame('2.4.62', $view->version);
        self::assertSame('Apache/2.4.62 (Debian) OpenSSL/3.0.15', $view->serverVersion);
        self::assertNull($view->versionStatus, 'no EndOfLife given');
        self::assertSame('event', $view->mpm);
        self::assertSame(1_040_619, $view->uptime);
        self::assertSame([0.42, 0.37, 0.31], [$view->load1, $view->load5, $view->load15]);

        self::assertSame(6.0, $view->busyWorkers->value);
        self::assertSame('3 / 50', $view->busyWorkers->detail);
        self::assertSame('Healthy', $view->busyWorkers->badge?->label);
        self::assertSame(0.8, $view->busyProcesses->value, '3 of 400 slots, rounded to one decimal');
        self::assertSame('3 / 400 slots', $view->busyProcesses->detail);
        self::assertSame('Stable', $view->busyProcesses->badge?->label);
        self::assertSame([
            'Waiting for connection' => 47,
            'Reading request' => 1,
            'Sending reply' => 1,
            'Keepalive' => 1,
            'Open slot' => 350,
        ], $view->scoreboard, 'in the order of the mod_status legend, the empty states left out');

        self::assertTrue($view->requestsPerSec->isPending());
        self::assertTrue($view->bytesPerSec->isPending());
        self::assertSame(25_600.0, $view->bytesPerRequest->value);
        self::assertSame('since server start', $view->bytesPerRequest->hint());
        self::assertSame(0.04, $view->durationPerRequest->value, '2080 seconds over 52000 requests');
        self::assertSame('since server start', $view->durationPerRequest->hint());
    }

    public function testTheTrafficComesFromTheDelta(): void
    {
        $delta = new Delta(Delta::flatten(Payloads::apache(51_900, 1_330_176_000, 2_077.5)), Delta::flatten(Payloads::apache()), 10);

        $view = new ApacheView($this->snapshot(Payloads::apache(), $delta), $this->gauges());

        self::assertSame(10.0, $view->requestsPerSec->value, '100 requests in 10 seconds');
        self::assertSame('req/s', $view->requestsPerSec->unit);
        self::assertSame(102_400.0, $view->bytesPerSec->value, '1000 KB in 10 seconds');
        self::assertSame(10_240.0, $view->bytesPerRequest->value);
        self::assertNull($view->bytesPerRequest->hint());
        self::assertSame(0.025, $view->durationPerRequest->value, '2.5 seconds over 100 requests');
        self::assertNull($view->durationPerRequest->hint());
    }

    public function testWithoutTotalDurationTheMeanSinceStartRemains(): void
    {
        $view = new ApacheView($this->snapshot(['total_duration' => null, 'duration_per_request' => 0.734] + Payloads::apache()), $this->gauges());

        self::assertSame(0.000734, $view->durationPerRequest->value);
        self::assertSame('since server start', $view->durationPerRequest->hint());
    }

    public function testServerTokensProdHidesTheVersionNumber(): void
    {
        $fetcher = new FixtureUrlFetcher(null);
        $view = new ApacheView($this->snapshot(['server_version' => 'Apache'] + Payloads::apache()), $this->gauges(), new EndOfLife(new ArrayAdapter(), new MockClock(), fetcher: $fetcher));

        self::assertSame('Apache', $view->version);
        self::assertNull($view->versionStatus);
        self::assertSame(0, $fetcher->calls, 'no version to look up');
    }

    public function testTheVersionStatusComesFromTheApacheHttpServerCycles(): void
    {
        $fetcher = new FixtureUrlFetcher((string) json_encode([['cycle' => '2.4', 'eol' => false, 'support' => true]]));

        $view = new ApacheView($this->snapshot(Payloads::apache()), $this->gauges(), new EndOfLife(new ArrayAdapter(), new MockClock('2026-10-20'), fetcher: $fetcher));

        self::assertSame('Active support', $view->versionStatus?->label);
        self::assertSame('https://endoflife.date/api/apache-http-server.json', $fetcher->url);
    }

    public function testMissingLoadAndCountersAreUnavailable(): void
    {
        $payload = ['load1' => null, 'load5' => null, 'load15' => null, 'total_accesses' => null, 'total_bytes' => null, 'duration_per_request' => null, 'total_duration' => null] + Payloads::apache();

        $view = new ApacheView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->load1);
        self::assertTrue($view->requestsPerSec->isUnavailable());
        self::assertFalse($view->requestsPerSec->isPending(), 'no later sample would bring it');
        self::assertTrue($view->bytesPerRequest->isUnavailable());
        self::assertTrue($view->durationPerRequest->isUnavailable());
    }

    public function testFailedCollectorIsUnavailable(): void
    {
        $view = new ApacheView(new ComponentSnapshot(Component::Apache, ['apache' => new CollectorResult('apache', [], 'boom', 1)]), $this->gauges());

        self::assertNull($view->version);
        self::assertNull($view->mpm);
        self::assertNull($view->busyWorkers->value);
        self::assertNull($view->busyWorkers->detail);
        self::assertNull($view->busyProcesses->value);
        self::assertNull($view->busyProcesses->detail);
        self::assertSame([], $view->scoreboard);
        self::assertTrue($view->requestsPerSec->isUnavailable());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Apache, ['apache' => new CollectorResult('apache', $data, null, 1_000, $delta)]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
