<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Psr\Clock\ClockInterface;

final readonly class ViewFactory
{
    public function __construct(
        private GaugeFactory $gauges,
        private SnapshotProvider $snapshots,
        private ComponentRegistry $registry,
        private ClockInterface $clock,
        private bool $messenger = true,
        private bool $scheduler = true,
        private bool $flex = true,
    ) {}

    public function create(Component $component, ComponentSnapshot $snapshot): object
    {
        return match ($component) {
            Component::System => new SystemView($snapshot, $this->gauges),
            Component::Php => new PhpView($snapshot, $this->gauges),
            Component::Mysql => new MysqlView($snapshot, $this->gauges, $this->clock->now()->getTimestamp()),
            Component::Postgresql => new PostgresqlView($snapshot, $this->gauges),
            Component::Redis => new RedisView($snapshot, $this->gauges),
            Component::Symfony => new SymfonyView($snapshot, $this->php(), $this->messenger, $this->scheduler, $this->flex),
        };
    }

    private function php(): ComponentSnapshot
    {
        $instance = $this->registry->instance(Component::Php, null);

        return $instance === null ? new ComponentSnapshot(Component::Php, [], 'This component is not available.') : $this->snapshots->get($instance);
    }
}
