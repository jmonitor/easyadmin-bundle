<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The "permission" option, on top of the access control of the dashboard (ROLE_ADMIN on /admin).
 */
final class PermissionTest extends WebTestCase
{
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

    public function testAnAdminWithoutThePermissionIsDeniedEverywhere(): void
    {
        $client = $this->client('admin', ['ROLE_ADMIN']);

        $client->request('GET', '/admin/jmonitor');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/jmonitor/php');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/jmonitor/php/fragment');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/jmonitor/php/refresh');
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePermissionIsCheckedBeforeTheComponent(): void
    {
        $client = $this->client('admin', ['ROLE_ADMIN']);

        $client->request('GET', '/admin/jmonitor/redis');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAUserWithThePermissionSeesThePages(): void
    {
        $client = $this->client('super', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);

        $client->request('GET', '/admin/jmonitor/php');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/jmonitor/php/fragment');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function client(string $user, array $roles): KernelBrowser
    {
        ConfigurableAdminKernel::$bundleConfiguration = [
            'permission' => 'ROLE_SUPER_ADMIN',
            'components' => ['mysql' => false, 'postgresql' => false, 'redis' => false, 'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY],
        ];
        $client = static::createClient();
        $client->loginUser(new InMemoryUser($user, $user, $roles));

        return $client;
    }
}
