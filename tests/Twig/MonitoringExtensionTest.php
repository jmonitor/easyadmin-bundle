<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Twig;

use Jmonitor\EasyAdminBundle\Twig\MonitoringExtension;
use PHPUnit\Framework\TestCase;

final class MonitoringExtensionTest extends TestCase
{
    public function testBytes(): void
    {
        $extension = new MonitoringExtension('https://dash.jmonitor.io/help/metric/');

        self::assertSame('—', $extension->bytes(null));
        self::assertSame('0 B', $extension->bytes(0));
        self::assertSame('512 B', $extension->bytes(512));
        self::assertSame('1.5 KB', $extension->bytes(1536));
        self::assertSame('128 MB', $extension->bytes(134217728));
        self::assertSame('1.4 GB', $extension->bytes(1_500_000_000));
        self::assertSame('1.40 GB', $extension->bytes(1_500_000_000, 2));
    }

    public function testDuration(): void
    {
        $extension = new MonitoringExtension('https://dash.jmonitor.io/help/metric/');

        self::assertSame('—', $extension->duration(null));
        self::assertSame('45s', $extension->duration(45));
        self::assertSame('2m 5s', $extension->duration(125));
        self::assertSame('3h 0m', $extension->duration(10_800));
        self::assertSame('1d 1h', $extension->duration(90_000));
        self::assertSame('12d 3h', $extension->duration(1_047_600));
    }

    public function testNumber(): void
    {
        $extension = new MonitoringExtension('https://dash.jmonitor.io/help/metric/');

        self::assertSame('—', $extension->number(null));
        self::assertSame('1 234', $extension->number(1234));
        self::assertSame('15.50', $extension->number(15.5, 2));
        self::assertSame('97.1', $extension->number(97.123, 1));
    }

    public function testAgo(): void
    {
        $extension = new MonitoringExtension(false);

        self::assertSame('—', $extension->ago(null));
        self::assertSame('just now', $extension->ago(1_000, 1_003));
        self::assertSame('42 s ago', $extension->ago(1_000, 1_042));
        self::assertSame('5 min ago', $extension->ago(1_000, 1_300));
        self::assertSame('in 3 h', $extension->ago(20_000, 9_000));
        self::assertSame('2 d ago', $extension->ago(0, 200_000));
    }

    public function testHelpUrl(): void
    {
        self::assertSame('https://dash.jmonitor.io/help/metric/mysql.connections_usage', (new MonitoringExtension('https://dash.jmonitor.io/help/metric/'))->helpUrl('mysql.connections_usage'));
        self::assertSame('https://example.com/help/redis.hit_rate', (new MonitoringExtension('https://example.com/help'))->helpUrl('redis.hit_rate'));
        self::assertNull((new MonitoringExtension(false))->helpUrl('redis.hit_rate'));
    }

    public function testHelpEnabled(): void
    {
        self::assertTrue((new MonitoringExtension('https://dash.jmonitor.io/help/metric/'))->helpEnabled());
        self::assertFalse((new MonitoringExtension(false))->helpEnabled());
    }

    public function testRegistersFiltersAndFunctions(): void
    {
        $extension = new MonitoringExtension(false);

        $filters = array_map(static fn($filter): string => $filter->getName(), $extension->getFilters());
        $functions = array_map(static fn($function): string => $function->getName(), $extension->getFunctions());

        self::assertSame(['jm_bytes', 'jm_duration', 'jm_number', 'jm_ago'], $filters);
        self::assertSame(['jm_help_url', 'jm_help_enabled'], $functions);
    }
}
