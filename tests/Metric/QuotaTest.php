<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Metric;

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Quota;
use PHPUnit\Framework\TestCase;

final class QuotaTest extends TestCase
{
    public function testACountBelowTheWarningHasNoStyle(): void
    {
        $quota = Quota::count('Operations', 250, 1000);

        self::assertSame(25.0, $quota->percent());
        self::assertFalse($quota->inBytes);
        self::assertNull($quota->style());
    }

    public function testAnIncludedAmountWarnsWithoutEverBeingCritical(): void
    {
        self::assertSame(BadgeStyle::Warning, Quota::bytes('Storage', 800, 1000)->style());
        self::assertSame(BadgeStyle::Warning, Quota::bytes('Storage', 5000, 1000)->style(), 'beyond the free tier it is billed, not refused');
        self::assertTrue(Quota::bytes('Storage', 800, 1000)->inBytes);
    }

    public function testALimitIsCriticalOnceReached(): void
    {
        self::assertSame(BadgeStyle::Warning, Quota::limit('Stored images', 99, 100)->style());
        self::assertSame(BadgeStyle::Danger, Quota::limit('Stored images', 100, 100)->style());
    }

    public function testWithoutAllowanceThereIsNoShare(): void
    {
        self::assertSame(0.0, Quota::count('Operations', 10, 0)->percent());
    }
}
