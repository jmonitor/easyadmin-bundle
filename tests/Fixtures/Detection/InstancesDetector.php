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
use Jmonitor\EasyAdminBundle\Detection\Server;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;

/**
 * Built from scalars only, so the compiled test container can instantiate it.
 */
final class InstancesDetector implements ComponentDetectorInterface
{
    /**
     * @param non-empty-list<array{id: string, detail?: ?string, error?: string, server?: string}> $instances
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

            $server = isset($instance['server']) ? self::server($instance['server']) : null;
            $set = new CollectorSet($this->component, [new CollectorSpec($collector, Lifetime::Static, perServer: $server !== null)]);

            return Instance::found($this->component, $set, $instance['id'], $instance['detail'] ?? null, $server);
        }, $this->instances));
    }

    private static function server(string $address): Server
    {
        [$host, $port] = array_pad(explode(':', $address, 2), 2, '3306');
        $host = strtolower($host);

        return new Server($host . ':' . $port, $host, (int) $port);
    }
}
