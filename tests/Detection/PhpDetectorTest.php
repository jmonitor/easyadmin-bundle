<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\Collector\Php\PhpCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Detection\PhpDetector;
use PHPUnit\Framework\TestCase;

final class PhpDetectorTest extends TestCase
{
    public function testPhpIsAlwaysDetectedAndTracksDeltas(): void
    {
        $detection = (new PhpDetector())->detect();

        self::assertTrue($detection->isFound());
        $spec = $detection->instances[0]->collectors?->get('php');
        self::assertNotNull($spec);
        self::assertInstanceOf(PhpCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
    }
}
