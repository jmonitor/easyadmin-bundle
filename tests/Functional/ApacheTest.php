<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Collector\ApacheStatusCollectorTest;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The SAPI and the mod_status report are fixtures injected by the kernel, the server software comes
 * with the request: nothing is fetched.
 */
final class ApacheTest extends WebTestCase
{
    private const QUIET = ['mysql' => false, 'postgresql' => false, 'redis' => false, 'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY];
    private const BEHIND_APACHE = ['SERVER_SOFTWARE' => 'Apache/2.4.62 (Debian)'];

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

    public function testTheTabIsServedBehindApache(): void
    {
        ConfigurableAdminKernel::$apache = ['sapi' => 'fpm-fcgi', 'status' => ApacheStatusCollectorTest::report()];
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/admin/jmonitor/apache', server: self::BEHIND_APACHE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'Apache');
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorTextContains('body', '2.4.62');
        self::assertSelectorTextContains('body', '3 / 400 slots');
        self::assertSelectorExists('a.jm-help[href="https://dash.jmonitor.io/help/metric/apache.busy_workers"]');
        self::assertSelectorExists('form[action="/admin/jmonitor/apache/refresh"]');

        $client->request('GET', '/admin/jmonitor/apache/fragment', server: self::BEHIND_APACHE);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Busy processes', (string) $client->getResponse()->getContent());
    }

    public function testTheTabIsServedByModPhp(): void
    {
        ConfigurableAdminKernel::$apache = ['sapi' => 'apache2handler', 'status' => ApacheStatusCollectorTest::report()];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/apache');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.alert-warning');
    }

    public function testTheTabIsAbsentBehindAnotherServer(): void
    {
        ConfigurableAdminKernel::$apache = ['sapi' => 'fpm-fcgi', 'status' => ApacheStatusCollectorTest::report()];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/php', server: ['SERVER_SOFTWARE' => 'nginx/1.27.2']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nav-tabs a[href="/admin/jmonitor/apache"]');

        $client->request('GET', '/admin/jmonitor/apache', server: ['SERVER_SOFTWARE' => 'nginx/1.27.2']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEnabledTrueBehindAnotherServerShowsTheDetectionError(): void
    {
        ConfigurableAdminKernel::$apache = ['sapi' => 'cli', 'status' => null];
        $client = $this->adminClient(['apache' => true]);

        $client->request('GET', '/admin/jmonitor/apache');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Apache is not serving this page.');
    }

    public function testAnUnreachableStatusPageShowsTheGuidance(): void
    {
        ConfigurableAdminKernel::$apache = ['sapi' => 'apache2handler', 'status' => null];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/apache');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Cannot read the Apache status at http://localhost/server-status. Enable mod_status with a "SetHandler server-status" location reachable from PHP and check "components.apache.endpoint". (Failed to fetch "http://localhost/server-status?auto": Connection refused)');
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
