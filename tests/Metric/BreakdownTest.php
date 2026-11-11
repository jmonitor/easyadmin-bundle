<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Metric;

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Breakdown;
use Jmonitor\EasyAdminBundle\Metric\BreakdownRow;
use PHPUnit\Framework\TestCase;

final class BreakdownTest extends TestCase
{
    public function testEachCountIsAShareOfTheTotalInTheGivenOrder(): void
    {
        $breakdown = Breakdown::of(['miss' => 25, 'hit' => 75]);

        self::assertEquals([new BreakdownRow('miss', 25, 25.0), new BreakdownRow('hit', 75, 75.0)], $breakdown->rows);
    }

    public function testLabelsAndStylesComeFromTheKeys(): void
    {
        $breakdown = Breakdown::of(
            [200 => 3, 'other' => 1, '' => 0],
            static fn(string $key): string => $key === '' ? '' : 'HTTP ' . $key,
            static fn(string $key): ?BadgeStyle => $key === '200' ? BadgeStyle::Success : null,
        );

        self::assertEquals([
            new BreakdownRow('HTTP 200', 3, 75.0, BadgeStyle::Success),
            new BreakdownRow('Other', 1, 25.0),
            new BreakdownRow('Unknown', 0, 0.0),
        ], $breakdown->rows);
    }

    public function testNoCountNoRow(): void
    {
        self::assertSame([], Breakdown::of([])->rows);
    }

    public function testCountsOfZeroAreNoShare(): void
    {
        self::assertSame(0.0, Breakdown::of(['a' => 0])->rows[0]->percent);
    }

    public function testTopKeepsTheLargestAndFoldsTheRest(): void
    {
        $counts = ['a' => 1, 'b' => 50, 'c' => 5, 'd' => 20, 'e' => 10, 'f' => 2, 'g' => 30];

        self::assertSame(['b' => 50, 'g' => 30, 'd' => 20, 'e' => 10, 'c' => 5, 'other' => 3], Breakdown::top($counts));
        self::assertSame(['b' => 50, 'other' => 68], Breakdown::top($counts, 1));
    }

    public function testTopWithoutRestHasNoOther(): void
    {
        self::assertSame(['b' => 2, 'a' => 1], Breakdown::top(['a' => 1, 'b' => 2]));
    }
}
