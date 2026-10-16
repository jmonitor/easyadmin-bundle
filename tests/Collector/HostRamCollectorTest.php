<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\HostRamCollector;
use PHPUnit\Framework\TestCase;

final class HostRamCollectorTest extends TestCase
{
    public function testItReadsOnlyTheTotalRamAndResetsTheAdapter(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getTotalMemory')->willReturn(8_589_934_592);
        $adapter->expects(self::never())->method('getAvailableMemory');
        $adapter->expects(self::once())->method('reset');

        self::assertSame(['total' => 8_589_934_592], (new CollectorRunner())->run(new HostRamCollector($adapter)));
    }
}
