<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Collector\FrankenPhpEndpointCollectorTest;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Jmonitor\EasyAdminBundle\WebNode;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The SAPI, the Caddy endpoint and "frankenphp version" are fixtures injected by the kernel:
 * nothing is fetched and no process starts.
 */
final class FrankenPhpTest extends WebTestCase
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

    public function testTheTabIsServedUnderFrankenPhp(): void
    {
        ConfigurableAdminKernel::$frankenphp = ['sapi' => 'frankenphp', 'metrics' => FrankenPhpEndpointCollectorTest::export('worker'), 'version' => "FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n"];
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/admin/jmonitor/frankenphp');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-tabs .nav-link.active', 'FrankenPHP');
        self::assertSelectorNotExists('.alert-warning');
        self::assertSelectorTextContains('body', '1.4.0');
        self::assertSelectorTextContains('body', 'index.php');
        self::assertSelectorExists('a.jm-help[href="https://dash.jmonitor.io/help/metric/frankenphp.busy_threads_percent"]');
        self::assertSelectorExists('form[action="/admin/jmonitor/frankenphp/refresh"]');
        $tabs = $crawler->filter('.nav-tabs .nav-link')->each(static fn($node): string => trim($node->text()));
        self::assertSame(array_search('PHP', $tabs, true) + 1, array_search('FrankenPHP', $tabs, true), 'the tab sits right after PHP');

        $client->request('GET', '/admin/jmonitor/frankenphp/fragment');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Busy workers', (string) $client->getResponse()->getContent());
    }

    public function testTheTabIsAbsentUnderAnotherSapi(): void
    {
        ConfigurableAdminKernel::$frankenphp = ['sapi' => 'cli', 'metrics' => FrankenPhpEndpointCollectorTest::export('worker'), 'version' => null];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nav-tabs a[href="/admin/jmonitor/frankenphp"]');

        $client->request('GET', '/admin/jmonitor/frankenphp');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEnabledTrueUnderAnotherSapiShowsTheDetectionError(): void
    {
        ConfigurableAdminKernel::$frankenphp = ['sapi' => 'cli', 'metrics' => null, 'version' => null];
        $client = $this->adminClient(['frankenphp' => true]);

        $client->request('GET', '/admin/jmonitor/frankenphp');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'FrankenPHP is not serving this page.');

        $client->request('GET', '/admin/jmonitor/frankenphp/fragment');
        self::assertResponseIsSuccessful();
    }

    public function testAnUnreachableEndpointShowsTheCaddyfileGuidance(): void
    {
        ConfigurableAdminKernel::$frankenphp = ['sapi' => 'frankenphp', 'metrics' => null, 'version' => null];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/frankenphp');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Cannot read the FrankenPHP metrics at http://localhost:2019/metrics. Enable them with the "metrics" global option of the Caddyfile and check "components.frankenphp.endpoint". (Failed to fetch "http://localhost:2019/metrics": Connection refused)');
        self::assertSelectorTextContains('body', 'n/a');
    }

    public function testAnExportWithoutFrankenPhpMetricsShowsTheGuidance(): void
    {
        ConfigurableAdminKernel::$frankenphp = ['sapi' => 'frankenphp', 'metrics' => FrankenPhpEndpointCollectorTest::export('caddy-only'), 'version' => null];
        $client = $this->adminClient();

        $client->request('GET', '/admin/jmonitor/frankenphp');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'No FrankenPHP metrics at http://localhost:2019/metrics.');
    }

    public function testRefreshDropsTheCachedVersion(): void
    {
        ConfigurableAdminKernel::$frankenphp = ['sapi' => 'frankenphp', 'metrics' => FrankenPhpEndpointCollectorTest::export('worker'), 'version' => null];
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/admin/jmonitor/frankenphp');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->appCache()->hasItem(self::versionKey()), 'the empty version is cached');

        $client->submit($crawler->filter('form[action="/admin/jmonitor/frankenphp/refresh"]')->form());

        self::assertResponseRedirects('/admin/jmonitor/frankenphp');
        self::assertFalse($this->appCache()->hasItem(self::versionKey()), 'Refresh drops the version too');
    }

    private static function versionKey(): string
    {
        return 'jmonitor_easyadmin.shell.' . (new WebNode())->keyPrefix() . 'frankenphp';
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
