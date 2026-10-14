<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;
use Jmonitor\Collector\System\Adapter\AdapterInterface;

/**
 * The total RAM of the host, without the rest of the system metrics.
 */
final readonly class HostRamCollector implements CollectorInterface, ResetInterface
{
    public function __construct(
        private AdapterInterface $adapter,
    ) {}

    public function collect(): array
    {
        return ['total' => $this->adapter->getTotalMemory()];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'system.ram_total';
    }

    public function reset(): void
    {
        $this->adapter->reset();
    }
}
