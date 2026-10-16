<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;

final readonly class SystemDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $osFamily = PHP_OS_FAMILY,
        private ?AdapterInterface $adapter = null,
    ) {}

    public function component(): Component
    {
        return Component::System;
    }

    public function detect(): Detection
    {
        if ($this->adapter === null && $this->osFamily !== 'Linux') {
            return Detection::unavailable(sprintf('System metrics are only collected on Linux, this server runs on %s.', $this->osFamily));
        }

        return Detection::found(new CollectorSet(Component::System, [
            new CollectorSpec(new SystemCollector($this->adapter), Lifetime::Dynamic),
        ]));
    }
}
