<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\View\ErrorReporting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorReportingTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, ?string}>
     */
    public static function levels(): iterable
    {
        yield 'unknown' => [null, null];
        yield 'nothing' => [0, '0'];
        yield 'everything' => [\E_ALL, 'E_ALL'];
        yield 'everything plus the E_STRICT bit' => [32767, 'E_ALL'];
        yield 'production default' => [\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED, 'E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED'];
        yield 'a few levels' => [\E_ERROR | \E_WARNING | \E_PARSE, 'E_ERROR | E_WARNING | E_PARSE'];
    }

    #[DataProvider('levels')]
    public function testDescribe(?int $level, ?string $expected): void
    {
        self::assertSame($expected, ErrorReporting::describe($level));
    }
}
