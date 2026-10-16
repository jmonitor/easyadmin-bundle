<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use PHPUnit\Framework\TestCase;

final class LifetimeTest extends TestCase
{
    public function testDynamicFollowsThePollingInterval(): void
    {
        self::assertSame(10, Lifetime::Dynamic->ttl(10, 3600));
        self::assertSame(30, Lifetime::Dynamic->ttl(30, 3600));
    }

    public function testDynamicNeverGoesBelowOneSecondWhenPollingIsDisabled(): void
    {
        self::assertSame(1, Lifetime::Dynamic->ttl(0, 3600));
    }

    public function testMinuteIsSixtySeconds(): void
    {
        self::assertSame(60, Lifetime::Minute->ttl(10, 3600));
    }

    public function testStaticFollowsTheConfiguredTtl(): void
    {
        self::assertSame(3600, Lifetime::Static->ttl(10, 3600));
        self::assertSame(120, Lifetime::Static->ttl(10, 120));
    }
}
