<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\EasyAdminBundle\Collector\CloudflareZoneLookup;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cloudflare\FixtureCloudflareClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CloudflareZoneLookupTest extends TestCase
{
    public function testAZoneNameIsLookedUpOnceAndKept(): void
    {
        $client = new FixtureCloudflareClient();
        $cache = new ArrayAdapter();

        $zone = (new CloudflareZoneLookup('Example.com', $cache, 3600))->find(self::api($client));
        (new CloudflareZoneLookup('example.com', $cache, 3600))->find(self::api($client));

        self::assertSame(FixtureCloudflareClient::ZONE_ID, $zone->id);
        self::assertSame('example.com', $zone->name);
        self::assertSame(FixtureCloudflareClient::ACCOUNT_ID, $zone->accountId);
        self::assertSame(['GET /zones'], $client->routes, 'the second lookup reads the cache pool');
    }

    public function testAZoneIdIsReadDirectly(): void
    {
        $client = new FixtureCloudflareClient();

        $zone = (new CloudflareZoneLookup(FixtureCloudflareClient::ZONE_ID, new ArrayAdapter(), 3600))->find(self::api($client));

        self::assertSame('example.com', $zone->name);
        self::assertSame(['GET /zones/{zone}'], $client->routes);
    }

    public function testForgetLooksTheZoneUpAgain(): void
    {
        $client = new FixtureCloudflareClient();
        $lookup = new CloudflareZoneLookup('example.com', new ArrayAdapter(), 3600);

        $lookup->find(self::api($client));
        $lookup->forget();
        $lookup->find(self::api($client));

        self::assertSame(['GET /zones', 'GET /zones'], $client->routes);
    }

    public function testARefusedTokenSaysWhichPermissionItNeeds(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare rejected the API token while looking up the zone "example.com": check that the token is valid and has the Zone › Zone › Read permission.');

        (new CloudflareZoneLookup('example.com', new ArrayAdapter(), 3600))->find(self::api(new FixtureCloudflareClient(['GET /zones' => 403])));
    }

    public function testAZoneTheTokenDoesNotSeeIsNotFound(): void
    {
        $cache = new ArrayAdapter();

        try {
            (new CloudflareZoneLookup('example.com', $cache, 3600))->find(self::api(new FixtureCloudflareClient(empty: ['GET /zones'])));
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(UnreachableServerException::class, $e);
            self::assertSame('Cloudflare zone "example.com" not found for this API token.', $e->getMessage());
        }

        $client = new FixtureCloudflareClient();
        (new CloudflareZoneLookup('example.com', $cache, 3600))->find(self::api($client));

        self::assertSame(['GET /zones'], $client->routes, 'the failure was not kept');
    }

    public function testAnUnknownZoneIdIsNotFound(): void
    {
        $this->expectExceptionMessage('Cloudflare zone "00000000000000000000000000000000" not found for this API token.');

        (new CloudflareZoneLookup('00000000000000000000000000000000', new ArrayAdapter(), 3600))->find(self::api(new FixtureCloudflareClient()));
    }

    public function testCloudflareNotAnsweringIsKeptLongerThanAPoll(): void
    {
        try {
            (new CloudflareZoneLookup('example.com', new ArrayAdapter(), 3600))->find(self::api(new FixtureCloudflareClient(down: true)));
            self::fail('no exception');
        } catch (UnreachableServerException $e) {
            self::assertSame('Cannot look up the Cloudflare zone "example.com".', $e->getMessage());
            self::assertStringContainsString('Connection refused', (string) $e->getPrevious()?->getMessage());
        }
    }

    private static function api(FixtureCloudflareClient $client): CloudflareApi
    {
        return new CloudflareApi($client, new Psr17Factory(), 'token');
    }
}
