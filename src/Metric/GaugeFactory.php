<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

final readonly class GaugeFactory
{
    public function __construct(
        private Ranges $ranges,
    ) {}

    public function create(string $metric, string $label, ?float $value, int $precision = 0, ?string $detail = null, ?string $hint = null): Gauge
    {
        if ($value === null) {
            return new Gauge($metric, $label, null, null, $detail, $hint);
        }

        $rounded = round($value, $precision);

        return new Gauge($metric, $label, $rounded, $this->ranges->badge($metric, $rounded), $detail, $hint);
    }

    public function fromRate(string $metric, string $label, Rate $rate, int $precision = 1, ?string $detail = null): Gauge
    {
        return $this->create($metric, $label, $rate->value, $precision, $detail, $rate->hint());
    }
}
