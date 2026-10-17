<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class RobustnessTest extends WebTestCase
{
    private const QUIET_SYMFONY = ['enabled' => 'auto', 'messenger' => false, 'scheduler' => false, 'flex' => false];

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

    public function testAPageWithoutAnyComponentExplainsWhy(): void
    {
        $client = $this->adminClient(['components' => [
            'system' => false, 'php' => false, 'symfony' => false, 'mysql' => false, 'redis' => false,
        ]]);

        $client->request('GET', '/admin/jmonitor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-info', 'No component to monitor');
        self::assertSelectorNotExists('[data-live]');
        self::assertSelectorNotExists('form[action$="/refresh"]');

        $client->request('GET', '/admin/jmonitor/php');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAComponentForcedOnWhoseDetectionFailsShowsTheReason(): void
    {
        $client = $this->adminClient(['components' => ['mysql' => true, 'redis' => true, 'symfony' => self::QUIET_SYMFONY]]);

        $client->request('GET', '/admin/jmonitor/mysql');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'No MySQL or MariaDB Doctrine connection found.');

        $client->request('GET', '/admin/jmonitor/mysql/fragment');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/jmonitor/redis');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'No Redis DSN found');
    }

    public function testAThrowingDetectorKeepsTheOtherTabsUp(): void
    {
        ConfigurableAdminKernel::$mysqlDetectorFailure = 'Malformed DSN';
        $client = $this->adminClient(['components' => ['mysql' => true, 'redis' => false, 'symfony' => self::QUIET_SYMFONY]]);

        $client->request('GET', '/admin/jmonitor/php');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/jmonitor/mysql');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Malformed DSN');
    }

    public function testAFailingCollectorShowsItsErrorInsideTheTab(): void
    {
        $client = $this->adminClient(['components' => [
            'redis' => ['enabled' => true, 'connections' => ['cache' => 'redis://127.0.0.1:1']], 'mysql' => false, 'symfony' => self::QUIET_SYMFONY,
        ]]);

        $client->request('GET', '/admin/jmonitor/redis');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'redis:');
    }

    public function testCachedDataOfAnotherVersionIsCollectedAgain(): void
    {
        $client = $this->adminClient(['components' => ['mysql' => false, 'redis' => false, 'symfony' => self::QUIET_SYMFONY]]);
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('cache.app');
        $cache->save($cache->getItem('jmonitor_easyadmin.collect.php')->set(['data' => 'not an array']));
        $cache->save($cache->getItem('jmonitor_easyadmin.previous.php')->set(['timestamp' => 'yesterday', 'values' => 'not an array']));

        $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', PHP_VERSION);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function adminClient(array $config): KernelBrowser
    {
        ConfigurableAdminKernel::$bundleConfiguration = $config;
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));

        return $client;
    }
}
