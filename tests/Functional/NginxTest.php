<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Collector\NginxStatusCollectorTest;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The stub_status page and the outputs of the commands are fixtures injected by the kernel, the
 * server software comes with the request: nothing is fetched or run.
 */
final class NginxTest extends WebTestCase
{
    private const QUIET = ['mysql' => false, 'postgresql' => false, 'redis' => false, 'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY];
    private const BEHIND_NGINX = ['SERVER_SOFTWARE' => 'nginx/1.27.2'];

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

    public function testTheTabIsServedBehindNginx(): void
    {
        ConfigurableAdminKernel::$nginx = ['status' => NginxStatusCollectorTest::stubStatus(), 'commands' => NginxStatusCollectorTest::commands()];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/nginx', server: self::BEHIND_NGINX);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'Nginx');
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorTextContains('body', '1.26.2');
        self::assertSelectorTextContains('body', '/etc/nginx/nginx.conf');
        self::assertSelectorExists('a.jm-help[href="https://dash.jmonitor.io/help/metric/nginx.active_connections"]');
        self::assertSelectorExists('form[action="/admin/jmonitor/nginx/refresh"]');

        $client->request('GET', '/admin/jmonitor/nginx/fragment', server: self::BEHIND_NGINX);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Active connections', (string) $client->getResponse()->getContent());
    }

    public function testWithoutTheCommandsTheVersionComesFromTheServerSoftware(): void
    {
        ConfigurableAdminKernel::$nginx = ['status' => NginxStatusCollectorTest::stubStatus(), 'commands' => []];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/nginx', server: self::BEHIND_NGINX);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorTextContains('body', '1.27.2');
        self::assertSelectorTextContains('body', 'nginx -T gave no configuration');
    }

    public function testTheTabIsAbsentBehindAnotherServer(): void
    {
        ConfigurableAdminKernel::$nginx = ['status' => NginxStatusCollectorTest::stubStatus(), 'commands' => []];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/php', server: ['SERVER_SOFTWARE' => 'Apache/2.4.62 (Debian)']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nav-tabs a[href="/admin/jmonitor/nginx"]');

        $client->request('GET', '/admin/jmonitor/nginx', server: ['SERVER_SOFTWARE' => 'Apache/2.4.62 (Debian)']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEnabledTrueBehindAnotherServerShowsTheDetectionError(): void
    {
        ConfigurableAdminKernel::$nginx = ['status' => null, 'commands' => []];
        $client = $this->adminClient(['nginx' => true]);

        $client->request('GET', '/admin/jmonitor/nginx');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Nginx is not serving this page (server software "unknown").');
    }

    public function testAnUnreachableStatusPageShowsTheGuidance(): void
    {
        ConfigurableAdminKernel::$nginx = ['status' => null, 'commands' => []];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/nginx', server: self::BEHIND_NGINX);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Cannot read the Nginx status at http://localhost/nginx_status. Enable stub_status in a location reachable from PHP and check "components.nginx.endpoint". (Failed to fetch "http://localhost/nginx_status": Connection refused)');
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
