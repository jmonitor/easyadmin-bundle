<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

enum Lifetime
{
    case Dynamic;
    case Minute;
    case Static;

    public function ttl(int $pollingInterval, int $staticTtl): int
    {
        return match ($this) {
            self::Dynamic => max(1, $pollingInterval),
            self::Minute => 60,
            self::Static => $staticTtl,
        };
    }
}
