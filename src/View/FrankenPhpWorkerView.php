<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;

/**
 * One worker script of the FrankenPHP tab. The delta is used only when the previous sample had
 * the same worker at the same index, so a worker added or removed between two polls never mixes
 * two scripts.
 */
final readonly class FrankenPhpWorkerView
{
    public string $name;
    /** The worker name as given in the Caddyfile, or the file name of a worker named by its path. */
    public string $file;
    public ?int $total;
    public ?int $busy;
    public Gauge $busyRate;
    public ?int $ready;
    public ?int $restarts;
    public ?int $restartsDelta;
    public ?int $crashes;
    public ?int $crashesDelta;
    public ?Badge $crashesBadge;
    public Rate $requestsPerSec;
    /** Seconds per request. */
    public Rate $avgExecTime;
    public ?int $queueDepth;
    /** Seconds spanned by the deltas, null without a usable delta. */
    public ?int $deltaElapsed;

    /**
     * @param array<string, mixed> $worker
     */
    public function __construct(array $worker, int $index, ?Delta $delta, GaugeFactory $gauges)
    {
        $this->name = Values::string($worker['name'] ?? null) ?? '';
        $this->file = str_contains($this->name, '/') ? basename($this->name) : $this->name;
        $this->total = Values::int($worker['total_workers'] ?? null);
        $this->busy = Values::int($worker['busy_workers'] ?? null);
        $this->busyRate = $gauges->create('frankenphp.worker_busy_workers_rate', 'Busy workers', Values::percent($this->busy, $this->total), 2, $this->busy === null || $this->total === null ? null : $this->busy . ' / ' . $this->total);
        $this->ready = Values::int($worker['ready_workers'] ?? null);
        $this->restarts = Values::int($worker['worker_restarts'] ?? null);
        $this->crashes = Values::int($worker['worker_crashes'] ?? null);
        $this->crashesBadge = match (true) {
            $this->crashes === null => null,
            $this->crashes === 0 => new Badge(BadgeStyle::Success, 'None'),
            default => new Badge(BadgeStyle::Danger, 'Detected'),
        };
        $this->queueDepth = Values::int($worker['worker_queue_depth'] ?? null);

        $key = 'workers.' . $index . '.';
        $delta = $delta !== null && ($delta->toArray()['previous'][$key . 'name'] ?? null) === $this->name ? $delta : null;

        $this->deltaElapsed = $delta?->elapsed;
        $this->restartsDelta = self::count($delta?->getValue($key . 'worker_restarts'));
        $this->crashesDelta = self::count($delta?->getValue($key . 'worker_crashes'));
        $this->requestsPerSec = Ratios::perSecond($delta, $key . 'worker_request_count', 'req/s', Values::int($worker['worker_request_count'] ?? null));
        $this->avgExecTime = Ratios::average(
            $delta,
            $key . 'worker_request_time',
            $key . 'worker_request_count',
            Values::float($worker['worker_request_time'] ?? null),
            Values::int($worker['worker_request_count'] ?? null),
            's',
        );
    }

    private static function count(int|float|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
