<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class FrankenPhpView
{
    public ?string $version;
    public ?Badge $mode;

    public ?int $busyThreads;
    public ?int $totalThreads;
    public Gauge $busyThreadsPercent;
    public ?int $queueDepth;

    public bool $workerMode;
    /** @var list<FrankenPhpWorkerView> */
    public array $workers;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $data = $snapshot->data('frankenphp');
        $delta = $snapshot->delta('frankenphp');

        $this->version = Values::string($data['version'] ?? null);
        $mode = Values::string($data['mode'] ?? null);
        $this->mode = match ($mode) {
            'worker' => new Badge(BadgeStyle::Success, 'Worker'),
            'classic' => new Badge(BadgeStyle::Neutral, 'Classic'),
            default => null,
        };

        $this->busyThreads = Values::int($data['busy_threads'] ?? null);
        $this->totalThreads = Values::int($data['total_threads'] ?? null);
        $this->busyThreadsPercent = $gauges->create('frankenphp.busy_threads_percent', 'Busy threads', Values::percent($this->busyThreads, $this->totalThreads), 2, $this->busyThreads === null || $this->totalThreads === null ? null : $this->busyThreads . ' / ' . $this->totalThreads);
        $this->queueDepth = Values::int($data['queue_depth'] ?? null);

        $this->workerMode = $mode === 'worker';
        $workers = [];

        foreach (is_array($data['workers'] ?? null) ? array_values($data['workers']) : [] as $index => $worker) {
            if (is_array($worker)) {
                $workers[] = new FrankenPhpWorkerView($worker, $index, $delta, $gauges);
            }
        }

        $this->workers = $workers;
    }
}
