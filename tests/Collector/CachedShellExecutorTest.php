<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\CachedShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cache\RecordingCachePool;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use PHPUnit\Framework\TestCase;

final class CachedShellExecutorTest extends TestCase
{
    public function testRunsTheCommandOnceAndCachesItsOutput(): void
    {
        $cache = new RecordingCachePool();
        $inner = new FixtureShellExecutor("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n");
        $executor = new CachedShellExecutor($cache, 3600, $inner);

        self::assertSame("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n", $executor->execute('frankenphp version'));
        self::assertSame("FrankenPHP v1.4.0 PHP 8.4.1 Caddy v2.9.0\n", (new CachedShellExecutor($cache, 3600, $inner))->execute('frankenphp version'));
        self::assertSame(1, $inner->calls);
        self::assertSame(3600, $cache->ttls['jmonitor_easyadmin.shell.' . md5('frankenphp version')], 'the output lives for static_ttl');
    }

    public function testANullOutputIsCachedToo(): void
    {
        $cache = new RecordingCachePool();
        $inner = new FixtureShellExecutor(null);
        $executor = new CachedShellExecutor($cache, 3600, $inner);

        self::assertNull($executor->execute('frankenphp version'));
        self::assertNull($executor->execute('frankenphp version'));
        self::assertSame(1, $inner->calls, 'a missing binary must not spawn a process at every poll');
    }

    public function testACachedValueOfAnotherShapeIsAMiss(): void
    {
        $cache = new RecordingCachePool();
        $cache->save($cache->getItem('jmonitor_easyadmin.shell.' . md5('frankenphp version'))->set('FrankenPHP v1'));
        $inner = new FixtureShellExecutor("FrankenPHP v1.4.0\n");

        self::assertSame("FrankenPHP v1.4.0\n", (new CachedShellExecutor($cache, 3600, $inner))->execute('frankenphp version'));
        self::assertSame(1, $inner->calls);
        self::assertSame(['output' => "FrankenPHP v1.4.0\n"], $cache->getItem('jmonitor_easyadmin.shell.' . md5('frankenphp version'))->get());
    }
}
