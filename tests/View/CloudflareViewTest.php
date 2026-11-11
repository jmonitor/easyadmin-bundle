<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Breakdown;
use Jmonitor\EasyAdminBundle\Metric\BreakdownRow;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Quota;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cloudflare\FixtureCloudflareClient;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\CloudflareView;
use Jmonitor\EasyAdminBundle\View\CloudflareVital;
use PHPUnit\Framework\TestCase;

final class CloudflareViewTest extends TestCase
{
    private const BLOCKS = ['zone', 'traffic', 'today', 'web_analytics', 'r2', 'images'];

    public function testTheZone(): void
    {
        $view = self::view();

        self::assertTrue($view->has('zone'));
        self::assertNull($view->notice('zone'));
        self::assertSame('example.com', $view->zoneName);
        self::assertEquals(new Badge(BadgeStyle::Success, 'active'), $view->zoneStatus);
        self::assertSame('Free', $view->plan);
        self::assertEquals(new Badge(BadgeStyle::Neutral, 'Off'), $view->developmentMode);
        self::assertNull($view->developmentModeEndsAt);
        self::assertSame('12 / 200', $view->dnsRecords);
    }

    public function testAPausedOrInactiveZoneIsFlagged(): void
    {
        self::assertEquals(new Badge(BadgeStyle::Danger, 'paused'), self::view(['zone' => ['paused' => true] + Payloads::cloudflareZone()])->zoneStatus);
        self::assertEquals(new Badge(BadgeStyle::Danger, 'pending'), self::view(['zone' => ['status' => 'pending'] + Payloads::cloudflareZone()])->zoneStatus);
    }

    public function testDevelopmentModeEndsFromTheTimeTheZoneWasRead(): void
    {
        $now = self::now();
        $zone = ['development_mode' => 3600] + Payloads::cloudflareZone();

        $on = self::view(['zone' => $zone], collectedAt: $now - 600);
        $over = self::view(['zone' => $zone], collectedAt: $now - 3600);

        self::assertEquals(new Badge(BadgeStyle::Danger, 'On'), $on->developmentMode);
        self::assertSame($now + 3000, $on->developmentModeEndsAt);
        self::assertEquals(new Badge(BadgeStyle::Neutral, 'Off'), $over->developmentMode, 'it ended since the zone was read');
        self::assertNull($over->developmentModeEndsAt);
    }

    public function testTheCertificate(): void
    {
        $view = self::view();

        self::assertNull($view->certificateNotice);
        self::assertSame(59, $view->certificateDaysLeft);
        self::assertEquals(new Badge(BadgeStyle::Success, 'active'), $view->certificateStatus);
        self::assertSame('GoogleTrustServices', $view->certificateIssuer);
        self::assertSame('2027-01-10', $view->certificateExpiresOn);
        self::assertSame(['example.com', '*.example.com'], $view->certificateHosts);
    }

    public function testACertificateIsRatedFromItsExpiryAndStatus(): void
    {
        $now = self::now();
        $certificate = static fn(array $values): array => ['zone' => ['certificate' => $values + Payloads::cloudflareZone()['certificate']] + Payloads::cloudflareZone()];

        $soon = self::view($certificate(['expires_at' => $now + 14 * 86400]));
        $expired = self::view($certificate(['expires_at' => $now - 3600]));
        $pending = self::view($certificate(['status' => 'pending_validation']));

        self::assertEquals(new Badge(BadgeStyle::Warning, 'expires soon'), $soon->certificateStatus);
        self::assertSame(14, $soon->certificateDaysLeft);
        self::assertEquals(new Badge(BadgeStyle::Danger, 'expired'), $expired->certificateStatus);
        self::assertSame(-1, $expired->certificateDaysLeft);
        self::assertEquals(new Badge(BadgeStyle::Danger, 'pending_validation'), $pending->certificateStatus);
    }

    public function testTheKeysOfTheZoneTheTokenCannotReadSayWhy(): void
    {
        $zone = Payloads::cloudflareZone();
        unset($zone['settings'], $zone['certificate'], $zone['dns_records']);

        $view = self::view(['zone' => $zone]);

        self::assertTrue($view->has('zone'));
        self::assertStringContainsString('Zone › SSL and Certificates › Read', (string) $view->certificateNotice);
        self::assertNull($view->certificateStatus);
        self::assertStringContainsString('Zone › Zone Settings › Read', (string) $view->settingsNotice);
        self::assertNull($view->sslMode);
        self::assertNull($view->dnsRecords);
    }

    public function testTheSettings(): void
    {
        $view = self::view();

        self::assertNull($view->settingsNotice);
        self::assertEquals(new Badge(BadgeStyle::Success, 'strict'), $view->sslMode);
        self::assertEquals(new Badge(BadgeStyle::Success, 'on'), $view->alwaysUseHttps);
        self::assertSame('1.2', $view->minTlsVersion);
        self::assertSame('Medium', $view->securityLevel);
        self::assertSame('Aggressive', $view->cacheLevel);
        self::assertSame(14400, $view->browserCacheTtl);
    }

    public function testRiskySettingsAreFlagged(): void
    {
        $settings = ['ssl' => 'flexible', 'always_use_https' => 'off', 'security_level' => 'under_attack', 'browser_cache_ttl' => 0];

        $view = self::view(['zone' => ['settings' => $settings] + Payloads::cloudflareZone()]);

        self::assertEquals(new Badge(BadgeStyle::Warning, 'flexible'), $view->sslMode);
        self::assertEquals(new Badge(BadgeStyle::Neutral, 'off'), $view->alwaysUseHttps);
        self::assertSame('Under attack', $view->securityLevel);
        self::assertSame(0, $view->browserCacheTtl);
        self::assertNull($view->minTlsVersion);
    }

    public function testTheTrafficWindow(): void
    {
        $view = self::view();

        self::assertTrue($view->has('traffic'));
        self::assertSame(15, $view->windowMinutes);
        self::assertSame(2000, $view->requests);
        self::assertEqualsWithDelta(133.33, $view->requestsPerMinute, 0.01);
        self::assertFalse($view->sampled);
        self::assertSame(55.0, $view->cacheHitRatio->value, 'hit and revalidated over every request');
        self::assertSame('cloudflare.cache_hit_ratio', $view->cacheHitRatio->metric);
        self::assertSame('1100 / 2000 requests', $view->cacheHitRatio->detail);
        self::assertNull($view->cacheHitRatio->badge, 'a dynamic site is not a bad one');
    }

    public function testTheOriginResponseTimeIsTheLatestMinuteThatReachedTheOrigin(): void
    {
        $view = self::view();

        self::assertSame(0.12, $view->originP50, 'in seconds, the latest minute was served from cache');
        self::assertSame(0.48, $view->originP95);
    }

    public function testWithoutAnyRequestReachingTheOriginThereIsNoResponseTime(): void
    {
        $traffic = Payloads::cloudflareTraffic();
        $traffic['minutes'] = [$traffic['minutes'][4]];

        $view = self::view(['traffic' => $traffic]);

        self::assertNull($view->originP50);
        self::assertNull($view->originP95);
    }

    public function testTheSecurityEventsLeaveOutTheRequestsNothingActedOn(): void
    {
        $view = self::view();

        self::assertSame(40, $view->blocked);
        self::assertSame(15, $view->challenged);
        self::assertSame(55, $view->mitigated);
        self::assertSame(2.75, $view->mitigatedShare);
        self::assertEquals(new Breakdown([
            new BreakdownRow('Block', 40, 40 / 60 * 100, BadgeStyle::Danger),
            new BreakdownRow('Managed challenge', 15, 25.0, BadgeStyle::Warning),
            new BreakdownRow('Skip', 5, 5 / 60 * 100, BadgeStyle::Neutral),
        ]), $view->securityByAction, 'the mitigations first');
        self::assertEquals(new Breakdown([
            new BreakdownRow('Custom rules', 45, 75.0),
            new BreakdownRow('Bot fight mode', 15, 25.0),
        ]), $view->securityBySource);
    }

    public function testTheBreakdowns(): void
    {
        $view = self::view();

        self::assertEquals(new Breakdown([
            new BreakdownRow('Hit', 900, 45.0, BadgeStyle::Success),
            new BreakdownRow('Dynamic', 600, 30.0, BadgeStyle::Info),
            new BreakdownRow('Miss', 300, 15.0, BadgeStyle::Warning),
            new BreakdownRow('Revalidated', 200, 10.0, BadgeStyle::Success),
        ]), $view->byCacheStatus);
        self::assertEquals(new Breakdown([
            new BreakdownRow('2xx', 1700, 85.0, BadgeStyle::Success),
            new BreakdownRow('3xx', 150, 7.5, BadgeStyle::Info),
            new BreakdownRow('4xx', 140, 140 / 2000 * 100, BadgeStyle::Warning),
            new BreakdownRow('5xx', 10, 0.5, BadgeStyle::Danger),
        ]), $view->byStatus, 'by class, in the order of the classes');
        self::assertSame(['www.example.com', 'api.example.com'], array_column($view->byHost->rows, 'label'));
        self::assertSame(['🇫🇷 France', '🇺🇸 United States', 'T1'], array_column($view->byCountry->rows, 'label'));
    }

    public function testAStatusOutsideTheClassesIsOther(): void
    {
        $traffic = Payloads::cloudflareTraffic();
        $traffic['window']['by_status'] = [200 => 90, 0 => 10];

        self::assertSame(['2xx', '3xx', '4xx', '5xx', 'Other'], array_column(self::view(['traffic' => $traffic])->byStatus->rows, 'label'));
    }

    public function testAWindowWithoutRequestsHasEmptyBreakdowns(): void
    {
        $traffic = Payloads::cloudflareTraffic();
        $traffic['window'] = ['from' => $traffic['window']['from'], 'to' => $traffic['window']['to'], 'requests' => 0, 'sampled' => false, 'by_cache_status' => [], 'by_status' => [], 'by_country' => [], 'by_host' => [], 'security' => ['by_action' => [], 'by_source' => []]];

        $view = self::view(['traffic' => $traffic]);

        self::assertSame(0, $view->requests);
        self::assertNull($view->cacheHitRatio->value);
        self::assertSame([], $view->byStatus->rows);
        self::assertSame([], $view->byCacheStatus->rows);
        self::assertSame(0, $view->mitigated);
        self::assertNull($view->mitigatedShare);
    }

    public function testToday(): void
    {
        $view = self::view();

        self::assertSame(86_000, $view->todayRequests);
        self::assertSame(50.0, $view->todayCachedPercent);
        self::assertSame(12_000, $view->todayPageViews);
        self::assertSame(3_400, $view->todayUniques);
        self::assertSame(5_368_709_120, $view->todayBytes);
        self::assertSame(3_221_225_472, $view->todayCachedBytes);
        self::assertSame(86, $view->todayThreats);
        self::assertSame(0.1, $view->todayThreatsPercent);
    }

    public function testTheWebVitalsAreRatedAgainstTheThresholdsOfGoogle(): void
    {
        $view = self::view();

        self::assertEquals([
            new CloudflareVital('LCP', 1.8),
            new CloudflareVital('INP', 0.24),
            new CloudflareVital('CLS', 0.31, isDuration: false),
            new CloudflareVital('TTFB', 0.42),
            new CloudflareVital('FCP', 1.1),
        ], $view->vitals);
        self::assertSame(['Good', 'Needs improvement', 'Poor', 'Good', null], array_map(static fn(CloudflareVital $vital): ?string => $vital->rating?->label, $view->vitals));
    }

    public function testAVitalWithoutSamplesHasNoRating(): void
    {
        $analytics = Payloads::cloudflareWebAnalytics();
        $analytics['vitals']['lcp_p75_ms'] = null;

        $lcp = self::view(['web_analytics' => $analytics])->vitals[0];

        self::assertNull($lcp->value);
        self::assertNull($lcp->rating);
    }

    public function testThePageLoads(): void
    {
        $view = self::view();

        self::assertSame(9_500, $view->pageLoads);
        self::assertSame(4_200, $view->visits);
        self::assertSame(1.2, $view->pageLoadP50);
        self::assertSame(2.1, $view->pageLoadP75);
        self::assertSame(['www.example.com', 'docs.example.com'], array_column($view->pageLoadsByHost->rows, 'label'));
        self::assertSame(['DNS' => 0.02, 'Connection' => 0.045, 'Request' => 0.18, 'Response' => 0.03, 'Page render' => 0.9], $view->timings);
    }

    public function testR2AgainstTheFreeTier(): void
    {
        $view = self::view();

        self::assertTrue($view->showsStorage());
        self::assertEquals([
            Quota::bytes('Storage', 9_127_854_080, 10 * 1024 ** 3),
            Quota::count('Class A operations', 12_000, 1_000_000),
            Quota::count('Class B operations', 640_000, 10_000_000),
        ], $view->r2Quotas);
        self::assertSame(BadgeStyle::Warning, $view->r2Quotas[0]->style(), 'above 80 % of the free tier');
        self::assertSame('2026-12-01', $view->r2ResetsOn);
        self::assertSame([['name' => 'assets', 'bytes' => 9_127_854_080, 'objects' => 5_400, 'storage_class' => 'Standard']], $view->buckets);
    }

    public function testImagesAgainstTheFreeTierAndTheStorageQuota(): void
    {
        $view = self::view();

        self::assertEquals([
            Quota::count('Unique transformations', 4_200, 5000),
            Quota::limit('Stored images', 120, 100_000),
        ], $view->imagesQuotas);
        self::assertSame(7_300, $view->imagesRequests);
        self::assertSame('2026-12-01', $view->imagesResetsOn);
    }

    public function testImagesWithoutStorageHaveNoStoredQuota(): void
    {
        $images = Payloads::cloudflareImages();
        unset($images['stored'], $images['stored_quota']);

        self::assertCount(1, self::view(['images' => $images])->imagesQuotas);
    }

    public function testABlockTheTokenCannotReadNamesThePermission(): void
    {
        $view = self::view(['traffic' => ['unavailable' => 'forbidden'], 'web_analytics' => ['unavailable' => 'forbidden']]);

        self::assertFalse($view->has('traffic'));
        self::assertSame('The API token lacks a permission for this data: Zone › Analytics › Read.', $view->notice('traffic'));
        self::assertSame('The API token lacks a permission for this data: Account › Account Analytics › Read.', $view->notice('web_analytics'));
        self::assertTrue($view->has('today'));
        self::assertNull($view->requests);
        self::assertSame([], $view->byStatus->rows);
    }

    public function testAProductTheAccountDoesNotUseSaysSo(): void
    {
        $view = self::view(['web_analytics' => ['unavailable' => 'not_configured'], 'r2' => ['unavailable' => 'not_configured'], 'images' => ['unavailable' => 'not_configured']]);

        self::assertSame('Web Analytics is not enabled on this zone.', $view->notice('web_analytics'));
        self::assertSame('No R2 bucket on this account.', $view->notice('r2'));
        self::assertSame('Cloudflare Images is not used on this account.', $view->notice('images'));
        self::assertFalse($view->showsStorage(), 'neither R2 nor Images: no storage section');
    }

    public function testTheStorageShowsWhenOneOfItsProductsIsUsedOrForbidden(): void
    {
        self::assertTrue(self::view(['r2' => ['unavailable' => 'not_configured']])->showsStorage());
        self::assertTrue(self::view(['r2' => ['unavailable' => 'forbidden'], 'images' => ['unavailable' => 'not_configured']])->showsStorage());
    }

    public function testAFailedOrPendingBlockHasNeitherDataNorNotice(): void
    {
        $snapshot = new ComponentSnapshot(Component::Cloudflare, [
            'cloudflare.zone' => new CollectorResult('cloudflare.zone', [], 'Cannot read the Cloudflare "zone" data.', 1_000),
            'cloudflare.traffic' => new CollectorResult('cloudflare.traffic', [], SnapshotProvider::IN_PROGRESS, 1_000),
        ]);

        $view = new CloudflareView($snapshot, self::gauges(), 1_000);

        foreach (self::BLOCKS as $block) {
            self::assertFalse($view->has($block));
            self::assertNull($view->notice($block));
        }

        self::assertNull($view->certificateNotice, 'the zone was not read: nothing says the token is at fault');
        self::assertNull($view->settingsNotice);
        self::assertFalse($view->showsStorage());
        self::assertSame([], $view->r2Quotas);
        self::assertSame([], $view->imagesQuotas);
    }

    /**
     * @param array<string, array<string, mixed>> $blocks the payloads replacing those of Payloads
     */
    private static function view(array $blocks = [], ?int $collectedAt = null): CloudflareView
    {
        $payloads = $blocks + [
            'zone' => Payloads::cloudflareZone(),
            'traffic' => Payloads::cloudflareTraffic(),
            'today' => Payloads::cloudflareToday(),
            'web_analytics' => Payloads::cloudflareWebAnalytics(),
            'r2' => Payloads::cloudflareR2(),
            'images' => Payloads::cloudflareImages(),
        ];
        $results = [];

        foreach ($payloads as $block => $payload) {
            $results['cloudflare.' . $block] = new CollectorResult('cloudflare.' . $block, $payload, null, $collectedAt ?? self::now());
        }

        return new CloudflareView(new ComponentSnapshot(Component::Cloudflare, $results), self::gauges(), self::now());
    }

    private static function now(): int
    {
        return (int) strtotime(FixtureCloudflareClient::NOW);
    }

    private static function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
