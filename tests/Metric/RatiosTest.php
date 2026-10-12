<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Metric;

use Jmonitor\EasyAdminBundle\Metric\RateSource;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use PHPUnit\Framework\TestCase;

final class RatiosTest extends TestCase
{
    public function testShareUsesTheDeltaWhenItHasTraffic(): void
    {
        $delta = new Delta(['hits' => 1000, 'misses' => 100], ['hits' => 1090, 'misses' => 110], 10);

        $rate = Ratios::share($delta, 'hits', 'misses', 1090, 110);

        self::assertSame(90.0, $rate->value);
        self::assertSame(RateSource::Delta, $rate->source);
        self::assertSame('%', $rate->unit);
    }

    public function testShareFallsBackToTheCountersWithoutDeltaOrTraffic(): void
    {
        $sinceStart = Ratios::share(null, 'hits', 'misses', 900, 100);
        self::assertSame(90.0, $sinceStart->value);
        self::assertTrue($sinceStart->isSinceStart());

        $quiet = new Delta(['hits' => 900, 'misses' => 100], ['hits' => 900, 'misses' => 100], 10);
        self::assertTrue(Ratios::share($quiet, 'hits', 'misses', 900, 100)->isSinceStart());
    }

    public function testShareWithoutAnyCounterIsUnavailable(): void
    {
        $rate = Ratios::share(null, 'hits', 'misses', null, null);

        self::assertTrue($rate->isUnavailable());
        self::assertNull($rate->value);
        self::assertSame('%', $rate->unit);
    }

    public function testPercentAndComplement(): void
    {
        $delta = new Delta(['disk' => 10, 'all' => 100, 'reads' => 5, 'requests' => 1000], ['disk' => 15, 'all' => 150, 'reads' => 7, 'requests' => 1100], 10);

        self::assertSame(10.0, Ratios::percent($delta, 'disk', 'all', 15, 150)->value);
        self::assertSame(98.0, Ratios::complement($delta, 'reads', 'requests', 7, 1100)->value);
        self::assertSame(99.36, Ratios::complement(null, 'reads', 'requests', 7, 1100)->value);
        self::assertTrue(Ratios::percent(null, 'disk', 'all', 15, 0)->isUnavailable());
    }

    public function testCounterResetIsNotNegative(): void
    {
        $delta = new Delta(['hits' => 1000, 'misses' => 100], ['hits' => 5, 'misses' => 1], 10);

        $rate = Ratios::share($delta, 'hits', 'misses', 5, 1);

        self::assertTrue($rate->isSinceStart(), 'a reset counter has no delta, the cumulative value is shown instead');
        self::assertSame(83.33, $rate->value);
    }

    public function testPerSecond(): void
    {
        $delta = new Delta(['Questions' => 100], ['Questions' => 250], 10);

        self::assertSame(15.0, Ratios::perSecond($delta, 'Questions', 'q/s')->value);
        self::assertSame('q/s', Ratios::perSecond($delta, 'Questions', 'q/s')->unit);
        self::assertTrue(Ratios::perSecond(null, 'Questions')->isUnavailable());
        self::assertTrue(Ratios::perSecond($delta, 'Unknown')->isUnavailable());
    }
}
