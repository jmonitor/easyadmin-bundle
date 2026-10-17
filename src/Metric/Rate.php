<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

final readonly class Rate
{
    public const SINCE_SERVER_START = 'since server start';

    /**
     * $part and $total are the counters behind a ratio, over the same span as its value.
     * $since names where cumulated counters start, shown with a SinceStart value.
     */
    public function __construct(
        public ?float $value,
        public RateSource $source,
        public string $unit = '',
        public int|float|null $part = null,
        public int|float|null $total = null,
        public string $since = self::SINCE_SERVER_START,
    ) {}

    public static function unavailable(string $unit = ''): self
    {
        return new self(null, RateSource::Unavailable, $unit);
    }

    public function isSinceStart(): bool
    {
        return $this->source === RateSource::SinceStart;
    }

    public function hint(): ?string
    {
        return $this->isSinceStart() ? $this->since : null;
    }

    public function isUnavailable(): bool
    {
        return $this->source === RateSource::Unavailable;
    }

    public function fraction(): ?string
    {
        return $this->part === null || $this->total === null ? null : $this->part . ' / ' . $this->total;
    }
}
