<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\AdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class MonitoringControllerTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return AdminKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    public function testTheAdminRoutesAreRegisteredUnderTheDashboard(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $routes = $router->getRouteCollection();

        self::assertSame('/admin/jmonitor', $routes->get('admin_jmonitor_index')?->getPath());
        self::assertSame('/admin/jmonitor/{component}', $routes->get('admin_jmonitor_component')?->getPath());
        self::assertSame('/admin/jmonitor/{component}/fragment', $routes->get('admin_jmonitor_fragment')?->getPath());
        self::assertSame('/admin/jmonitor/{component}/refresh', $routes->get('admin_jmonitor_refresh')?->getPath());
        self::assertSame(['POST'], $routes->get('admin_jmonitor_refresh')->getMethods());
        self::assertSame('system|php|frankenphp|apache|nginx|symfony|mysql|postgresql|redis', $routes->get('admin_jmonitor_component')->getRequirement('component'));
    }

    public function testTheIndexShowsTheOverview(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'Overview');
        self::assertSelectorExists('.jm-overview a[href="/admin/jmonitor/php"]');
        self::assertSelectorTextContains('.jm-overview', 'jmonitor/easyadmin-bundle');
        self::assertSelectorTextContains('.jm-overview', 'jmonitor/collector');
        self::assertSelectorTextContains('.jm-overview', 'Disabled in the configuration.');
        self::assertAnySelectorTextContains('.jm-overview-row.opacity-75', 'MySQL');
        self::assertSelectorNotExists('.jm-overview-row.opacity-75 a[href="/admin/jmonitor/php"]');
        self::assertSelectorTextContains('.jm-overview', 'Polling');
        self::assertSelectorNotExists('[data-live]');
        self::assertSelectorNotExists('form[action$="/refresh"]');
    }

    public function testTheComponentTabsLinkBackToTheOverview(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/php');

        self::assertSelectorExists('.nav-tabs a[href="/admin/jmonitor"]');
        self::assertSelectorNotExists('.nav-tabs a.active[href="/admin/jmonitor"]');
    }

    public function testTheSystemTabRendersInsideTheAdminLayout(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('System metrics are collected on Linux only.');
        }

        $client = $this->adminClient();

        $crawler = $client->request('GET', '/admin/jmonitor/system');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'System');
        self::assertSelectorExists('.nav-tabs a[href="/admin/jmonitor/php"]');
        self::assertSelectorExists('.nav-tabs a[href="/admin/jmonitor/symfony"]');
        self::assertSelectorNotExists('.nav-tabs a[href="/admin/jmonitor/redis"]');
        self::assertSelectorExists('[data-live-content]');
        self::assertSelectorExists('[data-live-toggle]');
        self::assertSelectorExists('.jm-gauge');
        self::assertSelectorTextContains('body', 'RAM usage');
        self::assertCount(1, $crawler->filter('script[src*="live.js"]'));
        self::assertMatchesRegularExpression('#^/bundles/jmonitoreasyadmin/monitoring\.css\?v=[0-9a-f]{8}$#', (string) $crawler->filter('link[href*="monitoring.css"]')->attr('href'));
        self::assertSelectorExists('form[action="/admin/jmonitor/system/refresh"]');
    }

    public function testHelpLinksOpenInThePanel(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.jm-help[data-jm-help][href="https://dash.jmonitor.io/help/metric/php.version"]');
        $frame = $crawler->filter('.offcanvas[data-jm-help-panel] template[data-jm-help-frame]');
        self::assertCount(1, $frame);
        self::assertStringContainsString('sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"', $frame->html());
        self::assertCount(1, $crawler->filter('script[src*="help.js"]'));
    }

    public function testTheFragmentHasNoLayout(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('System metrics are collected on Linux only.');
        }

        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/system/fragment');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $content);
        self::assertStringNotContainsString('nav-tabs', $content);
        self::assertStringContainsString('jm-gauge', $content);
    }

    public function testRefreshRedirectsBackToTheTab(): void
    {
        $client = $this->adminClient();
        $crawler = $client->request('GET', '/admin/jmonitor/php');

        $client->submit($crawler->filter('form[action="/admin/jmonitor/php/refresh"]')->form());

        self::assertResponseRedirects('/admin/jmonitor/php');
    }

    public function testRefreshWithoutAValidTokenIsRefused(): void
    {
        $client = $this->adminClient();

        $client->request('POST', '/admin/jmonitor/php/refresh', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testThePagesFollowTheAccessControlOfTheDashboard(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('viewer', 'viewer', ['ROLE_USER']));

        $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseStatusCodeSame(403);
    }

    public function testADisabledComponentIsNotFound(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/redis');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownComponentIsNotFound(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/postgres');

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePhpTabShowsTheRunningProcess(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'PHP');
        self::assertSelectorTextContains('body', PHP_VERSION);
        self::assertSelectorTextContains('body', 'OpCache');
        self::assertSelectorTextContains('body', 'APCu');
        self::assertSelectorTextContains('body', 'memory_limit');
        self::assertSelectorExists('#jm-php-extensions');
    }

    public function testTheSymfonyTabShowsTheKernelAndTheRecommendations(): void
    {
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/symfony');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'Symfony');
        self::assertSelectorTextContains('body', 'JmonitorEasyAdminBundle');
        self::assertSelectorTextContains('body', 'Recommendations');
        self::assertSelectorTextContains('body', 'realpath_cache_size');
        self::assertSelectorTextNotContains('body', 'Messenger', 'disabled in the test kernel');
        self::assertSelectorTextNotContains('body', 'Flex recipes');
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));

        return $client;
    }
}
