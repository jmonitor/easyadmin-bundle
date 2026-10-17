<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\CachedShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cache\RecordingCachePool;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Logging\RecordingLogger;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class CachedShellExecutorTest extends TestCase
{
    private const KEY = 'jmonitor_easyadmin.shell.frankenphp';

    public function testRunsTheCommandOnceAndCachesItsOutput(): void
    {
        $cache = new RecordingCachePool();
        $inner = new FixtureShellExecutor("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n");
        $executor = new CachedShellExecutor($cache, 3600, 'frankenphp', inner: $inner);

        self::assertSame("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n", $executor->execute('frankenphp version'));
        self::assertSame("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n", (new CachedShellExecutor($cache, 3600, 'frankenphp', inner: $inner))->execute('frankenphp version'));
        self::assertSame(1, $inner->calls);
        self::assertSame(3600, $cache->ttls[self::KEY], 'the output lives for static_ttl');
    }

    public function testEachCommandHasItsOwnOutputUnderTheCollectorKey(): void
    {
        $cache = new RecordingCachePool();
        $executor = new CachedShellExecutor($cache, 3600, 'frankenphp', inner: new FixtureShellExecutor("out\n"));

        $executor->execute('frankenphp version');
        $executor->execute('php -v');

        self::assertSame(['frankenphp version' => "out\n", 'php -v' => "out\n"], $cache->getItem(self::KEY)->get());
    }

    public function testANullOutputIsCachedAndLogged(): void
    {
        $cache = new RecordingCachePool();
        $inner = new FixtureShellExecutor(null);
        $logger = new RecordingLogger();
        $executor = new CachedShellExecutor($cache, 3600, 'frankenphp', $logger, $inner);

        self::assertNull($executor->execute('frankenphp version'));
        self::assertNull($executor->execute('frankenphp version'));
        self::assertSame(1, $inner->calls, 'a missing binary must not spawn a process at every poll');
        self::assertCount(1, $logger->records, 'logged once, when the command runs');
        self::assertSame([LogLevel::WARNING, '"{command}" gave no output.', ['command' => 'frankenphp version']], $logger->records[0]);
    }

    public function testACachedValueOfAnotherShapeIsAMiss(): void
    {
        $cache = new RecordingCachePool();
        $cache->save($cache->getItem(self::KEY)->set('FrankenPHP v1'));
        $inner = new FixtureShellExecutor("FrankenPHP v1.4.0\n");

        self::assertSame("FrankenPHP v1.4.0\n", (new CachedShellExecutor($cache, 3600, 'frankenphp', inner: $inner))->execute('frankenphp version'));
        self::assertSame(1, $inner->calls);
        self::assertSame(['frankenphp version' => "FrankenPHP v1.4.0\n"], $cache->getItem(self::KEY)->get());
    }

    public function testAFailureToStartTheProcessIsAnEmptyOutputAndIsLogged(): void
    {
        $cache = new RecordingCachePool();
        $inner = new class extends ShellExecutor {
            public int $calls = 0;

            public function execute(string $command): ?string
            {
                $this->calls++;

                throw new \LogicException('The Process class relies on proc_open, which is not available on your PHP installation.');
            }
        };
        $logger = new RecordingLogger();
        $executor = new CachedShellExecutor($cache, 3600, 'frankenphp', $logger, $inner);

        self::assertNull($executor->execute('frankenphp version'));
        self::assertNull($executor->execute('frankenphp version'));
        self::assertSame(1, $inner->calls, 'the failure is cached like an empty output');
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0][0]);
        self::assertSame('Cannot run "{command}": {reason}', $logger->records[0][1]);
        self::assertSame('frankenphp version', $logger->records[0][2]['command'] ?? null);
        self::assertSame('The Process class relies on proc_open, which is not available on your PHP installation.', $logger->records[0][2]['reason'] ?? null);
        self::assertInstanceOf(\LogicException::class, $logger->records[0][2]['exception'] ?? null, 'the exception goes to the handlers, like the other warnings of the channel');
    }

    public function testForgetDropsTheCachedOutputs(): void
    {
        $cache = new RecordingCachePool();
        $inner = new FixtureShellExecutor(null);
        $executor = new CachedShellExecutor($cache, 3600, 'frankenphp', inner: $inner);

        $executor->execute('frankenphp version');
        $executor->forget();

        self::assertFalse($cache->hasItem(self::KEY));
        $executor->execute('frankenphp version');
        self::assertSame(2, $inner->calls, 'the command runs again after a Refresh');
    }
}
