<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\NginxConfigCollector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use PHPUnit\Framework\TestCase;

final class NginxConfigCollectorTest extends TestCase
{
    public function testTheCommandsAreReadWithoutTheStatusPage(): void
    {
        $payload = (new NginxConfigCollector(new FixtureShellExecutor(self::commands())))->collect();

        self::assertSame(array_replace(Payloads::nginxConfig(), ['cpu_count' => PHP_OS_FAMILY === 'Linux' ? 4 : null]), $payload, 'NginxCollector reads the CPU count on Linux only');
    }

    public function testWithoutNginxVTheVersionIsTheOnePassedToPhpFpm(): void
    {
        $payload = (new NginxConfigCollector(new FixtureShellExecutor(null), '1.27.2'))->collect();

        self::assertSame('1.27.2', $payload['version']);
        self::assertNull($payload['config']['config_path']);
    }

    public function testNginxVWinsOverTheVersionPassedToPhpFpm(): void
    {
        $payload = (new NginxConfigCollector(new FixtureShellExecutor(self::commands()), '1.27.2'))->collect();

        self::assertSame('1.26.2', $payload['version']);
    }

    public function testNameAndVersion(): void
    {
        $collector = new NginxConfigCollector(new FixtureShellExecutor(null));

        self::assertSame('nginx.config', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    /**
     * @return array<string, ?string>
     */
    public static function commands(): array
    {
        return ['nginx -V 2>&1' => self::fixture('nginx-V.txt'), 'nginx -T' => self::fixture('nginx-T.txt'), 'getconf _NPROCESSORS_ONLN' => "4\n"];
    }

    private static function fixture(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Nginx/' . $file);
    }
}
