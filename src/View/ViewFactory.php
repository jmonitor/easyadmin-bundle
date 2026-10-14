<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;

final readonly class ViewFactory
{
    public function __construct(
        private GaugeFactory $gauges,
        private SnapshotProvider $snapshots,
    ) {}

    public function create(Component $component, ComponentSnapshot $snapshot): object
    {
        return match ($component) {
            Component::System => new SystemView($snapshot, $this->gauges),
            Component::Php => new PhpView($snapshot, $this->gauges),
            Component::Mysql => new MysqlView($snapshot, $this->gauges),
            Component::Redis => new RedisView($snapshot, $this->gauges),
            Component::Symfony => new SymfonyView($snapshot, $this->snapshots->get(Component::Php)),
        };
    }
}
