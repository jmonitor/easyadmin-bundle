<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Metric;

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\RateSource;
use PHPUnit\Framework\TestCase;

final class GaugeFactoryTest extends TestCase
{
    private GaugeFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }

    public function testRoundsAndBadges(): void
    {
        $gauge = $this->factory->create('system.ram_usage', 'RAM usage', 72.456, 1, detail: '7.2 GB / 10 GB');

        self::assertTrue($gauge->available());
        self::assertSame(72.5, $gauge->value);
        self::assertSame(BadgeStyle::Warning, $gauge->badge?->style);
        self::assertSame('7.2 GB / 10 GB', $gauge->detail);
        self::assertEqualsWithDelta(0.725, $gauge->fraction(), 0.0001);
    }

    public function testNullValueIsUnavailable(): void
    {
        $gauge = $this->factory->create('system.ram_usage', 'RAM usage', null);

        self::assertFalse($gauge->available());
        self::assertNull($gauge->badge);
        self::assertSame(0.0, $gauge->fraction());
    }

    public function testOutOfRangeValuesAreClampedForDrawingOnly(): void
    {
        self::assertSame(1.0, $this->factory->create('system.ram_usage', 'RAM', 150)->fraction());
        self::assertSame(0.0, $this->factory->create('system.ram_usage', 'RAM', -3)->fraction());
        self::assertNull($this->factory->create('system.ram_usage', 'RAM', 150)->badge);
        self::assertNull($this->factory->create('mysql.version', 'Version', 50)->badge);
    }

    public function testFromRateCarriesTheSinceStartHint(): void
    {
        $sinceStart = $this->factory->fromRate('redis.hit_rate', 'Hit rate', new Rate(97.123, RateSource::SinceStart, '%'));
        $live = $this->factory->fromRate('redis.hit_rate', 'Hit rate', new Rate(97.123, RateSource::Delta, '%'));
        $none = $this->factory->fromRate('redis.hit_rate', 'Hit rate', Rate::unavailable('%'));

        self::assertSame(97.1, $sinceStart->value);
        self::assertSame('since server start', $sinceStart->hint);
        self::assertNull($live->hint);
        self::assertFalse($none->available());
    }

    public function testFromRateCarriesTheOriginOfTheCounters(): void
    {
        $gauge = $this->factory->fromRate('postgres.cache_hit_ratio', 'Cache hit ratio', new Rate(99.5, RateSource::SinceStart, '%', since: 'since stats reset'));

        self::assertSame('since stats reset', $gauge->hint);
    }
}
