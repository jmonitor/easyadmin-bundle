<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

use Jmonitor\EasyAdminBundle\Snapshot\Delta;

/**
 * Ratios of counters, from a recent delta when there is one, else cumulated since the server started.
 */
final class Ratios
{
    public static function share(?Delta $delta, string $partKey, string $otherKey, int|float|null $part, int|float|null $other): Rate
    {
        if ($delta !== null) {
            $p = $delta->getValue($partKey);
            $o = $delta->getValue($otherKey);

            if ($p !== null && $o !== null && $p + $o > 0) {
                return new Rate(round($p / ($p + $o) * 100, 2), RateSource::Delta, '%', $p, $p + $o);
            }
        }

        if ($part !== null && $other !== null && $part + $other > 0) {
            return new Rate(round($part / ($part + $other) * 100, 2), RateSource::SinceStart, '%', $part, $part + $other);
        }

        return Rate::unavailable('%');
    }

    public static function percent(?Delta $delta, string $partKey, string $totalKey, int|float|null $part, int|float|null $total): Rate
    {
        if ($delta !== null) {
            $p = $delta->getValue($partKey);
            $t = $delta->getValue($totalKey);

            if ($p !== null && $t !== null && $t > 0) {
                return new Rate(round($p / $t * 100, 2), RateSource::Delta, '%', $p, $t);
            }
        }

        if ($part !== null && $total !== null && $total > 0) {
            return new Rate(round($part / $total * 100, 2), RateSource::SinceStart, '%', $part, $total);
        }

        return Rate::unavailable('%');
    }

    public static function complement(?Delta $delta, string $partKey, string $totalKey, int|float|null $part, int|float|null $total): Rate
    {
        if ($delta !== null) {
            $p = $delta->getValue($partKey);
            $t = $delta->getValue($totalKey);

            if ($p !== null && $t !== null && $t > 0) {
                return new Rate(round(($t - $p) / $t * 100, 2), RateSource::Delta, '%', $p, $t);
            }
        }

        if ($part !== null && $total !== null && $total > 0) {
            return new Rate(round(($total - $part) / $total * 100, 2), RateSource::SinceStart, '%', $part, $total);
        }

        return Rate::unavailable('%');
    }

    public static function perSecond(?Delta $delta, string $key, string $unit = '/s'): Rate
    {
        $value = $delta?->getPerSec($key);

        return $value === null ? Rate::unavailable($unit) : new Rate($value, RateSource::Delta, $unit);
    }
}
