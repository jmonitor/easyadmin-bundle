<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;

final readonly class CollectorSpec
{
    public string $name;

    public function __construct(
        public CollectorInterface $collector,
        public Lifetime $lifetime,
        public bool $trackDelta = false,
    ) {
        $this->name = $collector->getName();
    }
}
