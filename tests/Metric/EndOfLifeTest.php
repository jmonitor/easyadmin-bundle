<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Metric;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class EndOfLifeTest extends TestCase
{
    private const CYCLES = [
        ['cycle' => '8.5', 'releaseDate' => '2025-11-20', 'eol' => '2029-12-31', 'latest' => '8.5.1', 'lts' => false, 'support' => '2027-12-31'],
        ['cycle' => '8.2', 'releaseDate' => '2022-12-08', 'eol' => '2026-12-31', 'latest' => '8.2.30', 'lts' => false, 'support' => '2024-12-31'],
        ['cycle' => '8.1', 'releaseDate' => '2021-11-25', 'eol' => '2025-12-31', 'latest' => '8.1.34', 'lts' => false, 'support' => '2023-11-25'],
        ['cycle' => '8.0', 'releaseDate' => '2020-11-26', 'eol' => true, 'latest' => '8.0.30', 'lts' => false, 'support' => false],
        ['cycle' => '17', 'releaseDate' => '2024-09-26', 'eol' => '2029-11-08', 'latest' => '17.6', 'lts' => false],
        ['cycle' => '7', 'releaseDate' => '2015-12-03', 'eol' => '2026-10-20', 'latest' => '7.4.33', 'lts' => false],
        ['cycle' => '9.6', 'releaseDate' => '2016-09-29', 'eol' => false, 'latest' => '9.6.24', 'lts' => false, 'support' => true],
    ];

    /**
     * @return iterable<string, array{?string, ?Badge}>
     */
    public static function versions(): iterable
    {
        yield 'supported' => ['8.5.1', new Badge(BadgeStyle::Success, 'Active support')];
        yield 'security fixes only' => ['8.2.30', new Badge(BadgeStyle::Warning, 'Security fixes only')];
        yield 'past its end of life date' => ['8.1.34', new Badge(BadgeStyle::Danger, 'End of life')];
        yield 'flagged end of life' => ['8.0.30-0ubuntu0.22.04.1', new Badge(BadgeStyle::Danger, 'End of life')];
        yield 'no support date, before its end of life' => ['17.6 (Debian 17.6-1.pgdg120+1)', new Badge(BadgeStyle::Success, 'Active support')];
        yield 'no support date, on its end of life day' => ['7.4.33', new Badge(BadgeStyle::Danger, 'End of life')];
        yield 'two parts preferred to one' => ['9.6.24', new Badge(BadgeStyle::Success, 'Active support')];
        yield 'leading v' => ['v8.5.0', new Badge(BadgeStyle::Success, 'Active support')];
        yield 'unknown cycle' => ['8.3.0', null];
        yield 'no number' => ['unknown', null];
        yield 'no version' => [null, null];
    }

    #[DataProvider('versions')]
    public function testTheBadgeFollowsTheCycle(?string $version, ?Badge $expected): void
    {
        $endOfLife = new EndOfLife(new ArrayAdapter(), new MockClock('2026-10-20 12:00'), fetcher: new FixtureUrlFetcher(json_encode(self::CYCLES, JSON_THROW_ON_ERROR)));

        self::assertEquals($expected, $endOfLife->badge('php', $version));
    }

    public function testTheCyclesAreFetchedOncePerProduct(): void
    {
        $fetcher = new FixtureUrlFetcher(json_encode(self::CYCLES, JSON_THROW_ON_ERROR));
        $endOfLife = new EndOfLife(new ArrayAdapter(), new MockClock('2026-10-20'), fetcher: $fetcher);

        $endOfLife->badge('php', '8.5.1');
        $endOfLife->badge('php', '8.2.0');
        self::assertSame(1, $fetcher->calls);
        self::assertSame('https://endoflife.date/api/php.json', $fetcher->url);

        $endOfLife->badge('symfony', '7.4.0');
        self::assertSame(2, $fetcher->calls);
        self::assertSame('https://endoflife.date/api/symfony.json', $fetcher->url);
    }

    public function testNoVersionFetchesNothing(): void
    {
        $fetcher = new FixtureUrlFetcher(null);

        self::assertNull((new EndOfLife(new ArrayAdapter(), new MockClock(), fetcher: $fetcher))->badge('php', null));
        self::assertSame(0, $fetcher->calls);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function failures(): iterable
    {
        yield 'unreachable' => [null];
        yield 'not JSON' => ['<html>'];
        yield 'no cycle' => ['[]'];
        yield 'unexpected entries' => ['[{"cycle": "8.5", "eol": "soon"}, "8.4"]'];
    }

    #[DataProvider('failures')]
    public function testAFailureGivesNoBadgeAndIsKeptForItsLifetime(?string $response): void
    {
        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-10-20');
        $fetcher = new FixtureUrlFetcher($response);
        $endOfLife = new EndOfLife($cache, $clock, lifetime: 86_400, errorLifetime: 3_600, fetcher: $fetcher);

        self::assertNull($endOfLife->badge('php', '8.5.1'));
        self::assertNull($endOfLife->badge('php', '8.5.1'));
        self::assertSame(1, $fetcher->calls);

        $item = $cache->getItem('jmonitor_easyadmin.eol.php');
        self::assertTrue($item->isHit());
        $expiry = $item->getMetadata()['expiry'] ?? null;
        self::assertIsFloat($expiry);
        self::assertEqualsWithDelta(time() + 3_600, $expiry, 5);
    }

    public function testACachedPayloadOfUnknownShapeIsAMiss(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('jmonitor_easyadmin.eol.php')->set('8.5'));
        $fetcher = new FixtureUrlFetcher(json_encode(self::CYCLES, JSON_THROW_ON_ERROR));

        self::assertEquals(new Badge(BadgeStyle::Success, 'Active support'), (new EndOfLife($cache, new MockClock('2026-10-20'), fetcher: $fetcher))->badge('php', '8.5.1'));
        self::assertSame(1, $fetcher->calls);
    }
}
