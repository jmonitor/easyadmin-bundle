<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\RateSource;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class ApacheView
{
    /** The scoreboard states of mod_status, in the order of its legend. */
    private const STATES = [
        '_' => 'Waiting for connection',
        'S' => 'Starting up',
        'R' => 'Reading request',
        'W' => 'Sending reply',
        'K' => 'Keepalive',
        'D' => 'DNS lookup',
        'C' => 'Closing connection',
        'L' => 'Logging',
        'G' => 'Gracefully finishing',
        'I' => 'Idle cleanup',
        '.' => 'Open slot',
    ];

    /** The version number, or the server string when ServerTokens hides it. */
    public ?string $version;
    public ?string $serverVersion;
    public ?Badge $versionStatus;
    public ?Badge $mpm;
    public ?int $uptime;
    public ?float $load1;
    public ?float $load5;
    public ?float $load15;

    public Gauge $busyWorkers;
    public Gauge $busyProcesses;
    /** @var array<string, int> label => slots, for the states holding at least one */
    public array $scoreboard;

    public Rate $requestsPerSec;
    public Rate $bytesPerSec;
    public Rate $bytesPerRequest;
    public Rate $durationPerRequest;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges, ?EndOfLife $endOfLife = null)
    {
        $data = $snapshot->data('apache');
        $delta = $snapshot->delta('apache');

        $this->serverVersion = Values::string($data['server_version'] ?? null);
        $number = preg_match('~\bApache/(\d+(?:\.\d+){1,3})\b~i', $this->serverVersion ?? '', $m) === 1 ? $m[1] : null;
        $this->version = $number ?? $this->serverVersion;
        $this->versionStatus = $endOfLife?->badge('apache-http-server', $number);
        $mpm = Values::string($data['server_mpm'] ?? null);
        $this->mpm = $mpm === null ? null : new Badge(BadgeStyle::Info, $mpm);
        $this->uptime = Values::int($data['uptime'] ?? null);
        $this->load1 = Values::float($data['load1'] ?? null);
        $this->load5 = Values::float($data['load5'] ?? null);
        $this->load15 = Values::float($data['load15'] ?? null);

        $busy = Values::int($data['workers']['busy'] ?? null);
        $idle = Values::int($data['workers']['idle'] ?? null);
        $total = $busy === null || $idle === null ? null : $busy + $idle;
        $this->busyWorkers = $gauges->create('apache.busy_workers', 'Busy workers', Values::percent($busy, $total), 1, $total === null ? null : $busy . ' / ' . $total);

        $slots = [];
        foreach (is_array($data['scoreboard'] ?? null) ? $data['scoreboard'] : [] as $state => $count) {
            if (is_numeric($count)) {
                $slots[(string) $state] = (int) $count;
            }
        }

        $scoreboard = [];
        foreach (self::STATES as $state => $label) {
            if (($slots[$state] ?? 0) > 0) {
                $scoreboard[$label] = $slots[$state];
            }
        }

        $this->scoreboard = $scoreboard;
        $allSlots = array_sum($slots);
        $usedSlots = $allSlots - ($slots['_'] ?? 0) - ($slots['.'] ?? 0);
        $this->busyProcesses = $gauges->create('apache.busy_process', 'Busy processes', Values::percent($usedSlots, $allSlots), 1, $allSlots === 0 ? null : $usedSlots . ' / ' . $allSlots . ' slots');

        $accesses = Values::int($data['total_accesses'] ?? null);
        $bytes = Values::int($data['total_bytes'] ?? null);
        $this->requestsPerSec = Ratios::perSecond($delta, 'total_accesses', 'req/s', $accesses);
        $this->bytesPerSec = Ratios::perSecond($delta, 'total_bytes', '/s', $bytes);
        $this->bytesPerRequest = Ratios::average($delta, 'total_bytes', 'total_accesses', $bytes, $accesses, '');
        $duration = Ratios::average($delta, 'total_duration', 'total_accesses', Values::float($data['total_duration'] ?? null), $accesses, '');
        // Older versions print no Total Duration: their mean since start, in milliseconds, is the only one.
        $perRequest = Values::float($data['duration_per_request'] ?? null);
        $this->durationPerRequest = !$duration->isUnavailable() || $perRequest === null ? $duration : new Rate($perRequest / 1000, RateSource::SinceStart);
    }
}
