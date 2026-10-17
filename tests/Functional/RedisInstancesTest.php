<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * No Redis server is reached: a DSN on a closed port fails at collect time, the service
 * connection is a fake client registered by the kernel.
 */
final class RedisInstancesTest extends WebTestCase
{
    private const TWO = ['cache' => 'redis://127.0.0.1:1', 'queue' => ['service' => 'app.redis_queue']];

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

    public function testEachConnectionIsAPillAndTheFirstOneIsShownByDefault(): void
    {
        $client = $this->adminClient(self::TWO);

        $client->request('GET', '/admin/jmonitor/redis');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a.active[href="/admin/jmonitor/redis?connection=cache"]');
        self::assertSelectorTextContains('.jm-instances a.active', '127.0.0.1:1');
        self::assertSelectorExists('.jm-instances a[href="/admin/jmonitor/redis?connection=queue"]');
        self::assertSelectorExists('form[action="/admin/jmonitor/redis/refresh?connection=cache"]');
        self::assertSelectorExists('[data-live-url="/admin/jmonitor/redis/fragment?connection=cache"]');
        self::assertSelectorTextContains('.alert-warning', 'redis:');
    }

    public function testAServiceConnectionIsCollectedUnderItsOwnCacheKeys(): void
    {
        $client = $this->adminClient(self::TWO);

        $client->request('GET', '/admin/jmonitor/redis?connection=queue');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a.active[href$="connection=queue"]');
        self::assertSelectorTextContains('body', '7.2.4');
        self::assertSelectorNotExists('.alert-warning');
        $cache = self::getContainer()->get('cache.app');
        self::assertTrue($cache->hasItem('jmonitor_easyadmin.collect.redis.queue.redis'));
        self::assertFalse($cache->hasItem('jmonitor_easyadmin.collect.redis'));
    }

    public function testAnUnknownConnectionIsNotFound(): void
    {
        $client = $this->adminClient(self::TWO);

        $client->request('GET', '/admin/jmonitor/redis?connection=nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRefreshStaysOnTheInstance(): void
    {
        $client = $this->adminClient(self::TWO);

        $crawler = $client->request('GET', '/admin/jmonitor/redis?connection=queue');
        $client->submit($crawler->filter('form[action="/admin/jmonitor/redis/refresh?connection=queue"]')->form());

        self::assertResponseRedirects('/admin/jmonitor/redis?connection=queue');
    }

    public function testAnUnusableConnectionIsShownWithItsErrorEvenInAuto(): void
    {
        $client = $this->adminClient(['queue' => ['service' => 'app.redis_queue'], 'broken' => ['service' => 'app.nope']]);

        $client->request('GET', '/admin/jmonitor/redis');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.jm-instances a[href$="connection=broken"] .jm-instance-error[title=\'Unknown Redis service "app.nope".\']');

        $client->request('GET', '/admin/jmonitor/redis?connection=broken');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Unknown Redis service "app.nope".');
    }

    public function testASingleConnectionHasNoPillAndAnswersToItsId(): void
    {
        $client = $this->adminClient(['queue' => ['service' => 'app.redis_queue']]);

        $client->request('GET', '/admin/jmonitor/redis');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.jm-instances');

        $client->request('GET', '/admin/jmonitor/redis?connection=queue');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $connections
     */
    private function adminClient(array $connections): KernelBrowser
    {
        ConfigurableAdminKernel::$redisServices = ['app.redis_queue'];
        ConfigurableAdminKernel::$bundleConfiguration = ['components' => [
            'mysql' => false,
            'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY,
            'redis' => ['connections' => $connections],
        ]];
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));

        return $client;
    }
}
