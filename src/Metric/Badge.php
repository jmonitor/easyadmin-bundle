<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

final readonly class Badge
{
    public function __construct(
        public BadgeStyle $style,
        public string $label,
    ) {}
}
