<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

final readonly class Ranges
{
    /**
     * @param array<string, list<array{0: int|float, 1: int|float, 2: BadgeStyle, 3: string}>> $ranges
     */
    public function __construct(
        private array $ranges,
    ) {}

    public static function fromFile(string $path): self
    {
        return new self(require $path);
    }

    public function badge(string $metric, float $value): ?Badge
    {
        $rows = $this->ranges[$metric] ?? [];

        foreach ($rows as [$min, $max, $style, $label]) {
            if ($value >= $min && $value < $max) {
                return new Badge($style, $label);
            }
        }

        foreach ($rows as [, $max, $style, $label]) {
            if ((float) $max === $value) {
                return new Badge($style, $label);
            }
        }

        return null;
    }
}
