<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

final readonly class BreakdownRow
{
    public function __construct(
        public string $label,
        public int $count,
        public float $percent,
        public ?BadgeStyle $style = null,
    ) {}
}
