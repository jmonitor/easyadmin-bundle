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
use Jmonitor\EasyAdminBundle\View\FrankenPhpView;
use PHPUnit\Framework\TestCase;

final class FrankenPhpViewTest extends TestCase
{
    public function testWorkerModeWithADelta(): void
    {
        $view = new FrankenPhpView($this->snapshot(Payloads::frankenphpWorker(), self::delta()), $this->gauges());

        self::assertSame('1.4.0', $view->version);
        self::assertSame('Worker', $view->mode?->label);
        self::assertSame(BadgeStyle::Success, $view->mode->style);
        self::assertSame(13, $view->busyThreads);
        self::assertSame(16, $view->totalThreads);
        self::assertSame(25.0, $view->busyThreadsPercent->value, 'the 12 worker threads always count as busy: 1 of the 4 regular threads is');
        self::assertSame('1 / 4 regular threads', $view->busyThreadsPercent->detail);
        self::assertSame('12 threads run the workers', $view->busyThreadsPercent->hint);
        self::assertSame(BadgeStyle::Success, $view->busyThreadsPercent->badge?->style);
        self::assertSame(0, $view->queueDepth);
        self::assertTrue($view->workerMode);
        self::assertCount(2, $view->workers);

        $index = $view->workers[0];
        self::assertSame('/app/public/index.php', $index->name);
        self::assertSame('index.php', $index->file);
        self::assertSame(8, $index->total);
        self::assertSame(2, $index->busy);
        self::assertSame(25.0, $index->busyRate->value);
        self::assertSame('2 / 8', $index->busyRate->detail);
        self::assertSame(8, $index->ready);
        self::assertSame(3, $index->restarts);
        self::assertSame(1, $index->restartsDelta, '2 → 3 restarts over the interval');
        self::assertSame(1, $index->crashes);
        self::assertSame(0, $index->crashesDelta);
        self::assertSame('Detected', $index->crashesBadge?->label);
        self::assertSame(BadgeStyle::Danger, $index->crashesBadge->style);
        self::assertSame(10.0, $index->requestsPerSec->value, '100 requests in 10 seconds');
        self::assertSame('req/s', $index->requestsPerSec->unit);
        self::assertSame(0.025, $index->avgExecTime->value, '2.5 seconds for 100 requests');
        self::assertNull($index->avgExecTime->hint());
        self::assertSame(0, $index->queueDepth);
        self::assertSame(10, $index->deltaElapsed);

        $api = $view->workers[1];
        self::assertSame('api', $api->name);
        self::assertSame('api', $api->file, 'a worker name from the Caddyfile stays as is');
        self::assertSame(100.0, $api->busyRate->value);
        self::assertSame(BadgeStyle::Danger, $api->busyRate->badge?->style);
        self::assertSame('None', $api->crashesBadge?->label);
        self::assertTrue($api->requestsPerSec->isPending(), 'no previous sample for this worker');
        self::assertNull($api->restartsDelta);
        self::assertSame(0.02, $api->avgExecTime->value, '40 seconds for 2000 requests since the start');
        self::assertSame('since server start', $api->avgExecTime->hint());
        self::assertSame(2, $api->queueDepth);
    }

    public function testClassicMode(): void
    {
        $view = new FrankenPhpView($this->snapshot(Payloads::frankenphpClassic()), $this->gauges());

        self::assertSame('Classic', $view->mode?->label);
        self::assertSame(BadgeStyle::Neutral, $view->mode->style);
        self::assertFalse($view->workerMode);
        self::assertSame([], $view->workers);
        self::assertSame(18.75, $view->busyThreadsPercent->value);
        self::assertSame('3 / 16', $view->busyThreadsPercent->detail);
        self::assertNull($view->busyThreadsPercent->hint);
    }

    public function testWorkersTakingEveryThreadLeaveNoGauge(): void
    {
        $payload = Payloads::frankenphpWorker();
        $payload['total_threads'] = 12;
        $payload['busy_threads'] = 12;

        $view = new FrankenPhpView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->busyThreadsPercent->value);
        self::assertNull($view->busyThreadsPercent->detail);
        self::assertSame('12 threads run the workers', $view->busyThreadsPercent->hint);
    }

    public function testBusyThreadsBelowTheWorkerThreadsIsZero(): void
    {
        $payload = Payloads::frankenphpWorker();
        $payload['busy_threads'] = 10;

        $view = new FrankenPhpView($this->snapshot($payload), $this->gauges());

        self::assertSame(0.0, $view->busyThreadsPercent->value, 'workers still starting');
        self::assertSame('0 / 4 regular threads', $view->busyThreadsPercent->detail);
    }

    public function testARenamedWorkerIgnoresTheDelta(): void
    {
        $previous = self::previous();
        $previous['workers.0.name'] = '/old/index.php';
        $delta = new Delta($previous, Delta::flatten(Payloads::frankenphpWorker()), 10);

        $view = new FrankenPhpView($this->snapshot(Payloads::frankenphpWorker(), $delta), $this->gauges());

        self::assertNull($view->workers[0]->restartsDelta);
        self::assertNull($view->workers[0]->deltaElapsed);
        self::assertTrue($view->workers[0]->requestsPerSec->isUnavailable());
        self::assertTrue($view->workers[0]->avgExecTime->isSinceStart());
    }

    public function testAWorkerWithoutRequestCounterHasNoPendingRate(): void
    {
        $payload = Payloads::frankenphpWorker();
        unset($payload['workers'][0]['worker_request_count']);

        $view = new FrankenPhpView($this->snapshot($payload), $this->gauges());

        self::assertTrue($view->workers[0]->requestsPerSec->isUnavailable());
        self::assertFalse($view->workers[0]->requestsPerSec->isPending(), 'no later sample would bring it');
    }

    public function testACounterResetGivesNoDelta(): void
    {
        $previous = self::previous();
        $previous['workers.0.worker_restarts'] = 5;
        $delta = new Delta($previous, Delta::flatten(Payloads::frankenphpWorker()), 10);

        $view = new FrankenPhpView($this->snapshot(Payloads::frankenphpWorker(), $delta), $this->gauges());

        self::assertNull($view->workers[0]->restartsDelta);
        self::assertSame(10.0, $view->workers[0]->requestsPerSec->value, 'the other counters still have their delta');
    }

    public function testARequestCounterResetGivesNoRateAndACumulatedAverage(): void
    {
        $previous = self::previous();
        $previous['workers.0.worker_request_count'] = 10_500;
        $delta = new Delta($previous, Delta::flatten(Payloads::frankenphpWorker()), 10);

        $view = new FrankenPhpView($this->snapshot(Payloads::frankenphpWorker(), $delta), $this->gauges());

        self::assertTrue($view->workers[0]->requestsPerSec->isUnavailable(), 'no negative rate');
        self::assertTrue($view->workers[0]->avgExecTime->isSinceStart());
        self::assertSame(1, $view->workers[0]->restartsDelta, 'the other counters still have their delta');
    }

    public function testAWorkerWithoutTotalHasNoGauge(): void
    {
        $payload = Payloads::frankenphpWorker();
        unset($payload['workers'][0]['total_workers']);

        $view = new FrankenPhpView($this->snapshot($payload), $this->gauges());

        self::assertNull($view->workers[0]->busyRate->value);
        self::assertNull($view->workers[0]->busyRate->detail);
    }

    public function testZeroThreadsHasNoGauge(): void
    {
        $view = new FrankenPhpView($this->snapshot(['total_threads' => 0, 'busy_threads' => 0] + Payloads::frankenphpClassic()), $this->gauges());

        self::assertNull($view->busyThreadsPercent->value);
    }

    public function testFailedCollectorIsUnavailable(): void
    {
        $view = new FrankenPhpView(new ComponentSnapshot(Component::FrankenPhp, ['frankenphp' => new CollectorResult('frankenphp', [], 'boom', 1)]), $this->gauges());

        self::assertNull($view->version);
        self::assertNull($view->mode);
        self::assertNull($view->busyThreadsPercent->value);
        self::assertFalse($view->workerMode);
        self::assertSame([], $view->workers);
    }

    /**
     * @return array<string, mixed> the flattened previous sample: 100 requests and 2.5 seconds less on index.php
     */
    private static function previous(): array
    {
        $previous = Payloads::frankenphpWorker();
        $previous['workers'][0]['worker_request_count'] = 9_900;
        $previous['workers'][0]['worker_request_time'] = 123.0;
        $previous['workers'][0]['worker_restarts'] = 2;
        unset($previous['workers'][1]);

        return Delta::flatten($previous);
    }

    private static function delta(): Delta
    {
        return new Delta(self::previous(), Delta::flatten(Payloads::frankenphpWorker()), 10);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::FrankenPhp, ['frankenphp' => new CollectorResult('frankenphp', $data, null, 1_000, $delta)]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
