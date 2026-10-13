<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

final readonly class Rate
{
    public function __construct(
        public ?float $value,
        public RateSource $source,
        public string $unit = '',
    ) {}

    public static function unavailable(string $unit = ''): self
    {
        return new self(null, RateSource::Unavailable, $unit);
    }

    public function isSinceStart(): bool
    {
        return $this->source === RateSource::SinceStart;
    }

    public function isUnavailable(): bool
    {
        return $this->source === RateSource::Unavailable;
    }
}
