<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

/**
 * A current value and its peak against a maximum, drawn as a bar with a marker.
 */
final readonly class Peak
{
    public function __construct(
        public int $current,
        public int $peak,
        public int $max,
    ) {}

    public static function of(?int $current, ?int $peak, ?int $max): ?self
    {
        return $current === null || $peak === null || $max === null || $max <= 0 ? null : new self($current, $peak, $max);
    }

    public function currentPercent(): float
    {
        return min(100.0, $this->current / $this->max * 100);
    }

    public function peakPercent(): float
    {
        return min(100.0, $this->peak / $this->max * 100);
    }
}
