<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Availability;

final readonly class OverviewComponent
{
    /**
     * @param bool                   $failing   available, but every instance is in error
     * @param list<OverviewInstance> $instances the named instances shown, errors first
     * @param int                    $more      the named instances left out
     */
    public function __construct(
        public Component $component,
        public Availability $availability,
        public bool $failing,
        public ?string $detail,
        public array $instances = [],
        public int $more = 0,
    ) {}
}
