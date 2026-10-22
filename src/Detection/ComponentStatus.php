<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Component;

final readonly class ComponentStatus
{
    /**
     * @param list<Instance> $instances empty unless available
     * @param ?string        $reason    why a component left to auto-detection was not found
     */
    public function __construct(
        public Component $component,
        public Availability $availability,
        public array $instances,
        public ?string $reason = null,
    ) {}
}
