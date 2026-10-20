<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
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
        private EndOfLife $endOfLife,
        private bool $messenger = true,
        private bool $scheduler = true,
        private bool $flex = true,
    ) {}

    public function create(Component $component, ComponentSnapshot $snapshot): object
    {
        return match ($component) {
            Component::System => new SystemView($snapshot, $this->gauges),
            Component::Php => new PhpView($snapshot, $this->gauges, $this->endOfLife),
            Component::FrankenPhp => new FrankenPhpView($snapshot, $this->gauges),
            Component::Apache => new ApacheView($snapshot, $this->gauges, $this->endOfLife),
            Component::Mysql => new MysqlView($snapshot, $this->gauges, $this->clock->now()->getTimestamp(), $this->endOfLife),
            Component::Postgresql => new PostgresqlView($snapshot, $this->gauges, $this->endOfLife),
            Component::Redis => new RedisView($snapshot, $this->gauges, $this->endOfLife),
            Component::Symfony => new SymfonyView($snapshot, $this->php(), $this->messenger, $this->scheduler, $this->flex, $this->endOfLife),
        };
    }

    private function php(): ComponentSnapshot
    {
        $instance = $this->registry->instance(Component::Php, null);

        return $instance === null ? new ComponentSnapshot(Component::Php, [], Instance::UNAVAILABLE) : $this->snapshots->get($instance);
    }
}
