<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\RefreshableCollectorInterface;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\CloudflareDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cloudflare\FixtureCloudflareClient;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class CloudflareDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function incompleteConfigurations(): iterable
    {
        yield 'nothing' => [null, null];
        yield 'no zone' => ['token', null];
        yield 'no token' => [null, 'example.com'];
        yield 'an env var resolved to nothing' => ['', 'example.com'];
        yield 'blank' => ['token', ' '];
    }

    #[DataProvider('incompleteConfigurations')]
    public function testWithoutTokenOrZoneItIsUnavailable(?string $token, ?string $zone): void
    {
        $detection = (new CloudflareDetector($token, $zone, new ArrayAdapter(), 3600, new MockClock(), new NullLogger()))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('No Cloudflare zone configured: set components.cloudflare.api_token and components.cloudflare.zone.', $detection->error);
    }

    public function testDetectionNeverTouchesTheNetwork(): void
    {
        $client = new FixtureCloudflareClient();

        $detection = $this->detector($client)->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(Component::Cloudflare, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id, 'implicit instance');
        self::assertSame('example.com', $detection->instances[0]->detail);
        self::assertSame([], $client->routes);
    }

    public function testEachBlockIsACollectorWithItsOwnLifetime(): void
    {
        $collectors = $this->collectors(new FixtureCloudflareClient());

        self::assertSame([
            'cloudflare.zone' => Lifetime::Static,
            'cloudflare.traffic' => Lifetime::Minute,
            'cloudflare.today' => Lifetime::FiveMinutes,
            'cloudflare.web_analytics' => Lifetime::FiveMinutes,
            'cloudflare.r2' => Lifetime::QuarterHour,
            'cloudflare.images' => Lifetime::QuarterHour,
        ], array_combine($collectors->names(), array_map(static fn($spec): Lifetime => $spec->lifetime, $collectors->specs())));
    }

    public function testTheBlocksAreReadFromCloudflare(): void
    {
        $client = new FixtureCloudflareClient();
        $collectors = $this->collectors($client);

        self::assertSame(['GET /zones'], $client->routes, 'building the collectors looks the zone up');
        self::assertSame(Payloads::cloudflareZone(), self::collect($collectors, 'cloudflare.zone'));
        self::assertSame(Payloads::cloudflareTraffic(), self::collect($collectors, 'cloudflare.traffic'));
        self::assertSame(Payloads::cloudflareToday(), self::collect($collectors, 'cloudflare.today'));
        self::assertSame(Payloads::cloudflareWebAnalytics(), self::collect($collectors, 'cloudflare.web_analytics'));
        self::assertSame(Payloads::cloudflareR2(), self::collect($collectors, 'cloudflare.r2'));
        self::assertSame(Payloads::cloudflareImages(), self::collect($collectors, 'cloudflare.images'));
    }

    public function testTheZoneLookupIsKeptInTheCachePool(): void
    {
        $cache = new ArrayAdapter();
        $this->collectors(new FixtureCloudflareClient(), $cache);
        $client = new FixtureCloudflareClient();

        $this->collectors($client, $cache);

        self::assertSame([], $client->routes);
    }

    public function testRefreshForgetsTheZoneLookup(): void
    {
        $cache = new ArrayAdapter();
        $collector = $this->collectors(new FixtureCloudflareClient(), $cache)->get('cloudflare.zone')?->collector;
        self::assertInstanceOf(RefreshableCollectorInterface::class, $collector);

        $collector->refresh();
        $client = new FixtureCloudflareClient();
        $this->collectors($client, $cache);

        self::assertSame(['GET /zones'], $client->routes);
    }

    public function testARefusedTokenFailsTheBuildOfTheCollectors(): void
    {
        $this->expectExceptionMessage('Cloudflare rejected the API token while looking up the zone "example.com"');

        $this->collectors(new FixtureCloudflareClient(['GET /zones' => 401]));
    }

    public function testABlockTheTokenCannotReadIsNotAnError(): void
    {
        $collectors = $this->collectors(new FixtureCloudflareClient(['GraphQL Traffic' => 403]));

        self::assertSame(['unavailable' => 'forbidden'], self::collect($collectors, 'cloudflare.traffic'));
        self::assertSame(Payloads::cloudflareToday(), self::collect($collectors, 'cloudflare.today'), 'the other blocks keep working');
    }

    public function testAProductTheAccountDoesNotUseIsNotAnError(): void
    {
        $collectors = $this->collectors(new FixtureCloudflareClient(
            ['GET /accounts/{account}/images/v1/stats' => 404],
            ['GET /accounts/{account}/rum/site_info/list', 'GET /accounts/{account}/r2/buckets', 'GraphQL Images'],
        ));

        self::assertSame(['unavailable' => 'not_configured'], self::collect($collectors, 'cloudflare.web_analytics'));
        self::assertSame(['unavailable' => 'not_configured'], self::collect($collectors, 'cloudflare.r2'));
        self::assertSame(['unavailable' => 'not_configured'], self::collect($collectors, 'cloudflare.images'));
    }

    public function testAKeyOfTheZoneTheTokenCannotReadIsLeftOut(): void
    {
        $collectors = $this->collectors(new FixtureCloudflareClient(['GET /zones/{zone}/settings' => 403, 'GET /zones/{zone}/ssl/certificate_packs' => 403]));

        $zone = self::collect($collectors, 'cloudflare.zone');

        self::assertSame('example.com', $zone['name'] ?? null);
        self::assertArrayNotHasKey('settings', $zone);
        self::assertArrayNotHasKey('certificate', $zone);
    }

    public function testAnErrorOfCloudflareIsKeptLongerThanAPoll(): void
    {
        $collectors = $this->collectors(new FixtureCloudflareClient(['GraphQL Traffic' => 429]));

        try {
            self::collect($collectors, 'cloudflare.traffic');
            self::fail('no exception');
        } catch (UnreachableServerException $e) {
            self::assertSame('Cannot read the Cloudflare "traffic" data.', $e->getMessage());
            self::assertSame('GraphQL Traffic: HTTP 429: Refused by the fixture', $e->getPrevious()?->getMessage(), 'the cause is chained for SnapshotProvider::describe()');
        }
    }

    public function testOnceCloudflareIsUnreachableTheOtherBlocksDoNotWait(): void
    {
        $cache = new ArrayAdapter();
        $this->collectors(new FixtureCloudflareClient(), $cache);
        $client = new FixtureCloudflareClient(down: true);
        $collectors = $this->collectors($client, $cache);

        foreach ($collectors->names() as $name) {
            try {
                self::collect($collectors, $name);
                self::fail('no exception');
            } catch (UnreachableServerException $e) {
                self::assertStringStartsWith('Cannot read the Cloudflare "', $e->getMessage());
            }
        }

        self::assertSame(['GET /zones/{zone}'], $client->routes, 'one timeout for the six blocks');
    }

    private function detector(FixtureCloudflareClient $client, ?CacheItemPoolInterface $cache = null): CloudflareDetector
    {
        return new CloudflareDetector(" token\n", 'example.com', $cache ?? new ArrayAdapter(), 3600, new MockClock(FixtureCloudflareClient::NOW), new NullLogger(), $client);
    }

    private function collectors(FixtureCloudflareClient $client, ?CacheItemPoolInterface $cache = null): CollectorSet
    {
        $collectors = $this->detector($client, $cache)->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);
        $collectors->specs();

        return $collectors;
    }

    /**
     * @return array<string, mixed>
     */
    private static function collect(CollectorSet $collectors, string $name): array
    {
        $spec = $collectors->get($name);
        self::assertNotNull($spec);

        return $spec->collector->collect();
    }
}
