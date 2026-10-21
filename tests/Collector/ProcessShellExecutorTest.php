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

    public function testASuccessfulCommandPrintingOnItsErrorOutputOnlyGivesThatOutput(): void
    {
        self::assertSame('nginx version: nginx/1.26.2', trim((string) (new ProcessShellExecutor())->execute('echo nginx version: nginx/1.26.2 1>&2')), 'like "nginx -V"');
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
}
