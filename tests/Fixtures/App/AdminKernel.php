<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\App;

use Jmonitor\EasyAdminBundle\Tests\Fixtures\TestKernel;

/**
 * The kernel the functional tests boot through WebTestCase: MySQL and Redis are disabled,
 * the Symfony sub-collectors too, and the polling interval comes from JMONITOR_TEST_POLLING.
 */
final class AdminKernel extends TestKernel
{
    public function __construct(string $environment = 'test', bool $debug = true)
    {
        parent::__construct([
            'polling_interval' => self::pollingInterval(),
            'components' => [
                'mysql' => false,
                'postgresql' => false,
                'redis' => false,
                'symfony' => ['messenger' => false, 'scheduler' => false, 'flex' => false],
            ],
        ]);
    }

    public static function pollingInterval(): int
    {
        return (int) ($_SERVER['JMONITOR_TEST_POLLING'] ?? 10);
    }
}
