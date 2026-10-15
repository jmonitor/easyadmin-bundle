<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class MonitoringExtension extends AbstractExtension
{
    private const EMPTY = '—';
    private const HELP_URL = 'https://dash.jmonitor.io/help/metric/';

    public function getFilters(): array
    {
        return [
            new TwigFilter('jm_bytes', $this->bytes(...)),
            new TwigFilter('jm_duration', $this->duration(...)),
            new TwigFilter('jm_number', $this->number(...)),
            new TwigFilter('jm_ago', $this->ago(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jm_help_url', $this->helpUrl(...)),
        ];
    }

    public function bytes(int|float|null $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return self::EMPTY;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return sprintf('%d B', $value);
        }

        $formatted = number_format($value, $precision, '.', '');

        if ($precision === 1 && str_ends_with($formatted, '.0')) {
            $formatted = substr($formatted, 0, -2);
        }

        return $formatted . ' ' . $units[$unit];
    }

    public function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return self::EMPTY;
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm %ds', $minutes, $seconds % 60);
    }

    public function number(int|float|null $value, int $precision = 0): string
    {
        if ($value === null) {
            return self::EMPTY;
        }

        return number_format($value, $precision, '.', ' ');
    }

    public function ago(?int $timestamp, ?int $now = null): string
    {
        if ($timestamp === null) {
            return self::EMPTY;
        }

        $elapsed = ($now ?? time()) - $timestamp;
        $seconds = abs($elapsed);

        if ($seconds < 5) {
            return 'just now';
        }

        $distance = match (true) {
            $seconds < 60 => $seconds . ' s',
            $seconds < 3_600 => intdiv($seconds, 60) . ' min',
            $seconds < 86_400 => intdiv($seconds, 3_600) . ' h',
            default => intdiv($seconds, 86_400) . ' d',
        };

        return $elapsed > 0 ? $distance . ' ago' : 'in ' . $distance;
    }

    public function helpUrl(string $metric): string
    {
        return self::HELP_URL . $metric;
    }
}
