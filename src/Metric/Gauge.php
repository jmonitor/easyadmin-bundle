<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

/**
 * A percentage to draw as an arc. The value is kept as is; only the drawing is clamped.
 */
final readonly class Gauge
{
    public function __construct(
        public string $metric,
        public string $label,
        public ?float $value,
        public ?Badge $badge = null,
        public ?string $detail = null,
        public ?string $hint = null,
    ) {}

    public function available(): bool
    {
        return $this->value !== null;
    }

    public function fraction(): float
    {
        return max(0.0, min(100.0, $this->value ?? 0.0)) / 100;
    }
}
