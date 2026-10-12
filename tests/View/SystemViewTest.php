<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ranges;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\View\SystemView;
use PHPUnit\Framework\TestCase;

final class SystemViewTest extends TestCase
{
    public function testGaugesAndFacts(): void
    {
        $view = new SystemView($this->snapshot([
            'disk' => ['total' => 100_000_000_000, 'free' => 25_000_000_000],
            'cpu' => ['cores' => 4, 'load' => 62, 'load1' => 2.48, 'load5' => 1.1, 'load15' => 0.9],
            'ram' => ['total' => 8_000_000_000, 'available' => 2_000_000_000],
            'os' => ['pretty_name' => 'Debian GNU/Linux 12 (bookworm)', 'uptime' => 86_400],
            'time' => 1_760_000_000,
            'timezone' => 'Europe/Paris',
            'hostname' => 'web-1',
        ]), $this->gauges());

        self::assertTrue($view->available);
        self::assertSame(75.0, $view->ram->value);
        self::assertSame(BadgeStyle::Warning, $view->ram->badge?->style);
        self::assertSame(62.0, $view->cpu->value);
        self::assertSame(BadgeStyle::Warning, $view->cpu->badge?->style);
        self::assertSame(75.0, $view->disk->value);
        self::assertSame('Debian GNU/Linux 12 (bookworm)', $view->os);
        self::assertSame('web-1', $view->hostname);
        self::assertSame(86_400, $view->uptime);
        self::assertSame(4, $view->cores);
        self::assertSame('Europe/Paris', $view->timezone);
        self::assertSame(2.48, $view->load1);
        self::assertSame(8_000_000_000, $view->ramTotal);
        self::assertSame(2_000_000_000, $view->ramAvailable);
        self::assertSame(100_000_000_000, $view->diskTotal);
        self::assertSame(25_000_000_000, $view->diskFree);
    }

    public function testMissingDataGivesUnavailableGauges(): void
    {
        $view = new SystemView(new ComponentSnapshot(Component::System, [
            'system' => new CollectorResult('system', [], 'nproc: not found', 1_000),
        ]), $this->gauges());

        self::assertFalse($view->available);
        self::assertFalse($view->ram->available());
        self::assertFalse($view->cpu->available());
        self::assertFalse($view->disk->available());
        self::assertNull($view->hostname);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function snapshot(array $data): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::System, ['system' => new CollectorResult('system', $data, null, 1_000)]);
    }

    private function gauges(): GaugeFactory
    {
        return new GaugeFactory(Ranges::fromFile(\dirname(__DIR__, 2) . '/config/ranges.php'));
    }
}
