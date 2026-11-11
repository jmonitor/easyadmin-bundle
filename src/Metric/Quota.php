<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

/**
 * A counter against what a plan includes, or against a limit that cannot be exceeded.
 */
final readonly class Quota
{
    private const WARNING_PERCENT = 80;

    private function __construct(
        public string $label,
        public int $used,
        public int $allowed,
        public bool $inBytes,
        private bool $hardLimit,
    ) {}

    public static function count(string $label, int $used, int $allowed): self
    {
        return new self($label, $used, $allowed, false, false);
    }

    public static function bytes(string $label, int $used, int $allowed): self
    {
        return new self($label, $used, $allowed, true, false);
    }

    public static function limit(string $label, int $used, int $allowed): self
    {
        return new self($label, $used, $allowed, false, true);
    }

    public function percent(): float
    {
        return $this->allowed <= 0 ? 0.0 : $this->used / $this->allowed * 100;
    }

    public function style(): ?BadgeStyle
    {
        return match (true) {
            $this->hardLimit && $this->percent() >= 100 => BadgeStyle::Danger,
            $this->percent() >= self::WARNING_PERCENT => BadgeStyle::Warning,
            default => null,
        };
    }
}
