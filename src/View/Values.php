<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

/**
 * Collector payloads mix ints, numeric strings, "ON"/"OFF" flags and ini quantities.
 */
final class Values
{
    public static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 'on', 'true', 'yes' => true,
                '', '0', 'off', 'false', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    public static function percent(int|float|null $part, int|float|null $total): ?float
    {
        if ($part === null || $total === null || $total <= 0) {
            return null;
        }

        return $part / $total * 100;
    }

    /**
     * @return int|false|null false when the directive means "no limit" (-1)
     */
    public static function iniBytes(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value < 0 ? false : $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $bytes = ini_parse_quantity($value);

        return $bytes < 0 ? false : $bytes;
    }
}
