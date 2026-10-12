<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\Collector\Php\PhpCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;

/**
 * Without an endpoint the collector reads the PHP process serving the page, which is what we want.
 */
final readonly class PhpDetector implements ComponentDetectorInterface
{
    public function component(): Component
    {
        return Component::Php;
    }

    public function detect(): Detection
    {
        return Detection::found(new CollectorSet(Component::Php, [
            new CollectorSpec(new PhpCollector(), Lifetime::Dynamic, trackDelta: true),
        ]));
    }
}
