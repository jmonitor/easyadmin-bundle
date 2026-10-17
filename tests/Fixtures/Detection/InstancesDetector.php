<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentDetectorInterface;
use Jmonitor\EasyAdminBundle\Detection\Detection;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;

/**
 * Built from scalars only, so the compiled test container can instantiate it.
 */
final class InstancesDetector implements ComponentDetectorInterface
{
    /**
     * @param non-empty-list<array{id: string, detail?: ?string, error?: string}> $instances
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $instances,
    ) {}

    public function component(): Component
    {
        return $this->component;
    }

    public function detect(): Detection
    {
        return Detection::instances(array_map(function (array $instance): Instance {
            if (isset($instance['error'])) {
                return Instance::unavailable($this->component, $instance['error'], $instance['id'], $instance['detail'] ?? null);
            }

            $collector = new FakeCollector($this->component->value . '.variables', ['version' => '8.4.0']);

            return Instance::found($this->component, new CollectorSet($this->component, [new CollectorSpec($collector, Lifetime::Static)]), $instance['id'], $instance['detail'] ?? null);
        }, $this->instances));
    }
}
