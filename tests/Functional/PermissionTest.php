<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\ConfigurableAdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
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
        unset($_SERVER['JMONITOR_TEST_PERMISSION']);
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
    }

    /**
     * A 403 alone would not tell the permission from the missing CSRF token. The HTTP exception
     * answers 403 outside a firewall too, and carries Security's exception for the firewall.
     */
    public function testRefreshIsDeniedBeforeTheCsrfCheck(): void
    {
        $client = $this->client('admin', ['ROLE_ADMIN']);
        $client->catchExceptions(false);

        try {
            $client->request('POST', '/admin/jmonitor/php/refresh');
            self::fail('No exception thrown.');
        } catch (AccessDeniedHttpException $e) {
            self::assertStringContainsString('ROLE_SUPER_ADMIN', $e->getMessage());
            self::assertInstanceOf(AccessDeniedException::class, $e->getPrevious());
        }
    }

    public function testAnEmptyPermissionFromTheEnvironmentIsAMisconfiguration(): void
    {
        $_SERVER['JMONITOR_TEST_PERMISSION'] = '';
        $client = $this->client('super', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], '%env(JMONITOR_TEST_PERMISSION)%');
        $client->catchExceptions(false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('resolved to an empty string');

        $client->request('GET', '/admin/jmonitor/php');
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
    private function client(string $user, array $roles, string $permission = 'ROLE_SUPER_ADMIN'): KernelBrowser
    {
        ConfigurableAdminKernel::$bundleConfiguration = [
            'permission' => $permission,
            'components' => ['mysql' => false, 'postgresql' => false, 'redis' => false, 'symfony' => ConfigurableAdminKernel::QUIET_SYMFONY],
        ];
        $client = static::createClient();
        $client->loginUser(new InMemoryUser($user, $user, $roles));

        return $client;
    }
}
