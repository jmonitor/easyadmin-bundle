<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\ProcessShellExecutor;
use PHPUnit\Framework\TestCase;

final class ProcessShellExecutorTest extends TestCase
{
    public function testReturnsTheOutput(): void
    {
        self::assertSame('hello', trim((string) (new ProcessShellExecutor())->execute('echo hello')));
    }

    public function testAnEmptyOutputIsAnExceptionCarryingTheExitCodeAndTheErrorOutput(): void
    {
        try {
            (new ProcessShellExecutor())->execute('jmonitor-easyadmin-no-such-binary version');
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/^exit code [1-9]\d* \(.+\): .*jmonitor-easyadmin-no-such-binary/s', $e->getMessage());
        }
    }

    public function testAFailedCommandIsAnExceptionEvenWithAnOutput(): void
    {
        try {
            (new ProcessShellExecutor())->execute('jmonitor-easyadmin-no-such-binary -V 2>&1');
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/^exit code [1-9]\d* \(.+\): .*jmonitor-easyadmin-no-such-binary/s', $e->getMessage());
        }
    }
}
