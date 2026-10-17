<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The MySQL detector is replaced by a fixture giving several instances, since the tests reach no MySQL server.
 */
final class InstancesTest extends WebTestCase
{
    private const TWO_INSTANCES = [['id' => 'default', 'detail' => 'shop'], ['id' => 'legacy', 'detail' => 'erp']];

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

    public function testTheFirstInstanceIsShownWithoutParameterAndThePillsLinkToEachOne(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $client->request('GET', '/admin/jmonitor/mysql');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a.active[href="/admin/jmonitor/mysql?connection=default"]');
        self::assertSelectorTextContains('.jm-instances a.active', 'shop');
        self::assertSelectorExists('.jm-instances a[href="/admin/jmonitor/mysql?connection=legacy"]');
        self::assertSelectorExists('form[action="/admin/jmonitor/mysql/refresh?connection=default"]');
        self::assertSelectorExists('[data-live-url="/admin/jmonitor/mysql/fragment?connection=default"]');
        self::assertSelectorExists('.nav-tabs a[href="/admin/jmonitor/mysql"]');
    }

    public function testTheConnectionParameterSelectsAnInstance(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $client->request('GET', '/admin/jmonitor/mysql?connection=legacy');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a.active[href$="connection=legacy"]');
        self::assertSelectorExists('[data-live-url="/admin/jmonitor/mysql/fragment?connection=legacy"]');

        $client->request('GET', '/admin/jmonitor/mysql/fragment?connection=legacy');
        self::assertResponseIsSuccessful();
    }

    public function testAnEmptyConnectionParameterMeansTheFirstInstance(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $client->request('GET', '/admin/jmonitor/mysql?connection=');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a.active[href$="connection=default"]');
    }

    public function testAnUnknownConnectionIsNotFound(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $client->request('GET', '/admin/jmonitor/mysql?connection=nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnArrayConnectionParameterIsABadRequest(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $client->request('GET', '/admin/jmonitor/mysql?connection[]=x');

        self::assertResponseStatusCodeSame(400);
    }

    public function testASingleInstanceComponentRejectsTheParameter(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $client->request('GET', '/admin/jmonitor/php?connection=default');

        self::assertResponseStatusCodeSame(404);
    }

    public function testASingleInstanceShowsNoPills(): void
    {
        $client = $this->adminClient([['id' => 'default', 'detail' => 'shop']]);

        $client->request('GET', '/admin/jmonitor/mysql');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.jm-instances');

        $client->request('GET', '/admin/jmonitor/mysql?connection=default');
        self::assertResponseIsSuccessful();
    }

    public function testAnInstanceInErrorShowsItsReasonAndIsMarkedInThePills(): void
    {
        $client = $this->adminClient([['id' => 'default', 'detail' => 'shop'], ['id' => 'broken', 'error' => 'Unknown Doctrine connection "broken".']]);

        $client->request('GET', '/admin/jmonitor/mysql');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a[href$="connection=broken"] .jm-instance-error[title=\'Unknown Doctrine connection "broken".\']');

        $client->request('GET', '/admin/jmonitor/mysql?connection=broken');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Unknown Doctrine connection "broken".');
    }

    public function testRefreshStaysOnTheInstance(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES);

        $crawler = $client->request('GET', '/admin/jmonitor/mysql?connection=legacy');
        $client->submit($crawler->filter('form[action="/admin/jmonitor/mysql/refresh?connection=legacy"]')->form());

        self::assertResponseRedirects('/admin/jmonitor/mysql?connection=legacy');
    }

    public function testRefreshOfASingleInstanceGoesBackToTheBareUrl(): void
    {
        $client = $this->adminClient([['id' => 'default', 'detail' => 'shop']]);

        $crawler = $client->request('GET', '/admin/jmonitor/mysql');
        $client->submit($crawler->filter('form[action="/admin/jmonitor/mysql/refresh?connection=default"]')->form());

        self::assertResponseRedirects('/admin/jmonitor/mysql');
    }

    public function testTheIndexRedirectsToTheComponentWithoutParameter(): void
    {
        $client = $this->adminClient(self::TWO_INSTANCES, ['system' => false, 'php' => false, 'symfony' => false]);

        $client->request('GET', '/admin/jmonitor');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/admin/jmonitor/mysql', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * @param list<array{id: string, detail?: ?string, error?: string}> $instances
     * @param array<string, mixed>                                     $components
     */
    private function adminClient(array $instances, array $components = []): KernelBrowser
    {
        ConfigurableAdminKernel::$mysqlInstances = $instances;
        ConfigurableAdminKernel::$bundleConfiguration = ['components' => $components + ['mysql' => true, 'redis' => false, 'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY]];
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));

        return $client;
    }
}
