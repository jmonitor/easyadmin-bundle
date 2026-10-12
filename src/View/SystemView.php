<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class SystemView
{
    public bool $available;
    public Gauge $ram;
    public Gauge $cpu;
    public Gauge $disk;
    public ?string $os;
    public ?string $hostname;
    public ?string $timezone;
    public ?int $uptime;
    public ?int $cores;
    public ?int $ramTotal;
    public ?int $ramAvailable;
    public ?int $diskTotal;
    public ?int $diskFree;
    public ?float $load1;
    public ?float $load5;
    public ?float $load15;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $data = $snapshot->data('system');

        $this->available = $snapshot->has('system');
        $this->ramTotal = Values::int($data['ram']['total'] ?? null);
        $this->ramAvailable = Values::int($data['ram']['available'] ?? null);
        $this->diskTotal = Values::int($data['disk']['total'] ?? null);
        $this->diskFree = Values::int($data['disk']['free'] ?? null);
        $this->cores = Values::int($data['cpu']['cores'] ?? null);
        $this->load1 = Values::float($data['cpu']['load1'] ?? null);
        $this->load5 = Values::float($data['cpu']['load5'] ?? null);
        $this->load15 = Values::float($data['cpu']['load15'] ?? null);
        $this->os = Values::string($data['os']['pretty_name'] ?? null);
        $this->uptime = Values::int($data['os']['uptime'] ?? null);
        $this->timezone = Values::string($data['timezone'] ?? null);
        $this->hostname = Values::string($data['hostname'] ?? null);

        $ramUsed = $this->ramTotal !== null && $this->ramAvailable !== null ? $this->ramTotal - $this->ramAvailable : null;
        $diskUsed = $this->diskTotal !== null && $this->diskFree !== null ? $this->diskTotal - $this->diskFree : null;

        $this->ram = $gauges->create('system.ram_usage', 'RAM usage', Values::percent($ramUsed, $this->ramTotal));
        $this->cpu = $gauges->create('system.cpu_usage', 'CPU usage', Values::float($data['cpu']['load'] ?? null));
        $this->disk = $gauges->create('system.disk_usage', 'Disk usage', Values::percent($diskUsed, $this->diskTotal));
    }
}
