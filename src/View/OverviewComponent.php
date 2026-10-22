<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Availability;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;

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

    public function isAvailable(): bool
    {
        return $this->availability === Availability::Available;
    }

    public function style(): BadgeStyle
    {
        return match (true) {
            $this->failing => BadgeStyle::Danger,
            $this->isAvailable() => BadgeStyle::Success,
            default => BadgeStyle::Neutral,
        };
    }
}
