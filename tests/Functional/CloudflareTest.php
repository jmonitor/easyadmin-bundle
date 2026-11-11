<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The Cloudflare API is a fixture client injected by the kernel: nothing is fetched.
 */
final class CloudflareTest extends WebTestCase
{
    private const QUIET = ['mysql' => false, 'postgresql' => false, 'redis' => false, 'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY];

    protected static function getKernelClass(): string
    {
        return ConfigurableAdminKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ConfigurableAdminKernel::reset();
        restore_exception_handler();
    }

    public function testTheTabIsServedForAConfiguredZone(): void
    {
        ConfigurableAdminKernel::$cloudflare = [];
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/admin/jmonitor/cloudflare');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'Cloudflare');
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorTextContains('body', 'example.com');
        self::assertSelectorTextContains('body', 'last 15 minutes at the edge');
        self::assertSelectorTextContains('body', 'GoogleTrustServices');
        self::assertSelectorTextContains('body', 'Unique visitors');
        self::assertSelectorTextContains('body', 'Web Vitals');
        self::assertSelectorTextContains('body', 'Class A operations');
        self::assertSelectorExists('a.jm-help[href="https://dash.jmonitor.io/help/metric/cloudflare.cache_hit_ratio"]');
        self::assertSelectorExists('form[action="/admin/jmonitor/cloudflare/refresh"]');
        self::assertSelectorNotExists('.jm-node:not([hidden])', 'Cloudflare is not read on one web server');
        $tabs = $crawler->filter('.nav-tabs .nav-link')->each(static fn($node): string => trim($node->text()));
        self::assertSame('Cloudflare', end($tabs), 'the last tab');

        $client->request('GET', '/admin/jmonitor/cloudflare/fragment');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Daily totals', (string) $client->getResponse()->getContent());
    }

    public function testTheOverviewNamesTheZone(): void
    {
        ConfigurableAdminKernel::$cloudflare = [];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-overview a[href="/admin/jmonitor/cloudflare"]');
        self::assertSelectorTextContains('.jm-overview', 'example.com');
    }

    public function testTheTabIsAbsentWithoutTokenAndZone(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nav-tabs a[href="/admin/jmonitor/cloudflare"]');

        $client->request('GET', '/admin/jmonitor/cloudflare');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAConfiguredZoneShowsTheTabWithoutCallingCloudflare(): void
    {
        $client = $this->adminClient(['cloudflare' => ['api_token' => 'token', 'zone' => 'example.com']]);

        $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.nav-tabs a[href="/admin/jmonitor/cloudflare"]');
    }

    public function testEnabledTrueWithoutZoneShowsTheDetectionError(): void
    {
        $client = $this->adminClient(['cloudflare' => true]);

        $client->request('GET', '/admin/jmonitor/cloudflare');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'No Cloudflare zone configured: set components.cloudflare.api_token and components.cloudflare.zone.');
    }

    public function testARefusedTokenIsOneErrorForTheWholeTab(): void
    {
        ConfigurableAdminKernel::$cloudflare = ['statuses' => ['GET /zones' => 403]];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/cloudflare');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.alert-warning');
        self::assertSelectorTextContains('.alert-warning', 'Cloudflare rejected the API token while looking up the zone "example.com": check that the token is valid and has the Zone › Zone › Read permission.');
    }

    public function testCloudflareNotAnsweringIsOneErrorForTheWholeTab(): void
    {
        ConfigurableAdminKernel::$cloudflare = ['down' => true];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/cloudflare');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.alert-warning');
        self::assertSelectorTextContains('.alert-warning', 'Cannot look up the Cloudflare zone "example.com". (GET /zones: Connection refused');
    }

    public function testABlockTheTokenCannotReadIsExplainedInPlace(): void
    {
        ConfigurableAdminKernel::$cloudflare = ['statuses' => ['GraphQL Traffic' => 403], 'empty' => ['GET /accounts/{account}/rum/site_info/list']];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/cloudflare');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorTextContains('body', 'The API token lacks a permission for this data: Zone › Analytics › Read.');
        self::assertSelectorTextContains('body', 'Web Analytics is not enabled on this zone.');
        self::assertSelectorTextContains('body', 'Daily totals');
    }

    public function testRefreshForgetsTheZoneAndItsData(): void
    {
        ConfigurableAdminKernel::$cloudflare = [];
        $client = $this->adminClient();
        $crawler = $client->request('GET', '/admin/jmonitor/cloudflare');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->appCache()->hasItem('jmonitor_easyadmin.collect.cloudflare.zone'));
        self::assertTrue($this->appCache()->hasItem(self::lookupKey()));

        $client->submit($crawler->filter('form[action="/admin/jmonitor/cloudflare/refresh"]')->form());

        self::assertResponseRedirects('/admin/jmonitor/cloudflare');
        self::assertFalse($this->appCache()->hasItem('jmonitor_easyadmin.collect.cloudflare.zone'));
        self::assertFalse($this->appCache()->hasItem(self::lookupKey()));
    }

    private static function lookupKey(): string
    {
        return 'jmonitor_easyadmin.cloudflare.zone.' . hash('xxh128', 'example.com');
    }

    private function appCache(): CacheItemPoolInterface
    {
        $cache = static::getContainer()->get('cache.app');
        assert($cache instanceof CacheItemPoolInterface);

        return $cache;
    }

    /**
     * @param array<string, mixed> $components
     */
    private function adminClient(array $components = []): KernelBrowser
    {
        ConfigurableAdminKernel::$bundleConfiguration = ['components' => $components + self::QUIET];
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));

        return $client;
    }
}
