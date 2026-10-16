<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Functional;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\AdminKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class PollingDisabledTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $_SERVER['JMONITOR_TEST_POLLING'] = '0';
    }

    public static function tearDownAfterClass(): void
    {
        unset($_SERVER['JMONITOR_TEST_POLLING']);
    }

    protected static function getKernelClass(): string
    {
        return AdminKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    public function testNoLiveScriptNorToggleWhenPollingIsDisabled(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', 'admin', ['ROLE_ADMIN']));

        $crawler = $client->request('GET', '/admin/jmonitor/php');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('script[src*="live.js"]'));
        self::assertCount(1, $crawler->filter('script[src*="help.js"]'), 'the help panel does not depend on the polling');
        self::assertSelectorNotExists('[data-live-toggle]');
        self::assertSelectorNotExists('[data-live]');
        self::assertSelectorExists('form[action="/admin/jmonitor/php/refresh"]', 'the manual refresh stays');
        self::assertSelectorTextContains('body', PHP_VERSION);
    }
}
