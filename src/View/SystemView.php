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
    public ?int $time;
    public ?int $uptime;
    public ?int $cores;
    public ?int $ramTotal;
    public ?int $ramAvailable;
    public ?int $diskTotal;
    public ?int $diskFree;
    public ?int $ramUsed;
    public ?int $diskUsed;
    public ?float $load1;

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
        $this->os = Values::string($data['os']['pretty_name'] ?? null);
        $this->uptime = Values::int($data['os']['uptime'] ?? null);
        $this->timezone = Values::string($data['timezone'] ?? null);
        $this->time = Values::int($data['time'] ?? null);
        $this->hostname = Values::string($data['hostname'] ?? null);

        $this->ramUsed = $this->ramTotal !== null && $this->ramAvailable !== null ? $this->ramTotal - $this->ramAvailable : null;
        $this->diskUsed = $this->diskTotal !== null && $this->diskFree !== null ? $this->diskTotal - $this->diskFree : null;

        $this->ram = $gauges->create('system.ram_usage', 'RAM usage', Values::percent($this->ramUsed, $this->ramTotal));
        $this->cpu = $gauges->create('system.cpu_usage', 'CPU usage', Values::float($data['cpu']['load'] ?? null));
        $this->disk = $gauges->create('system.disk_usage', 'Disk usage', Values::percent($this->diskUsed, $this->diskTotal));
    }
}
