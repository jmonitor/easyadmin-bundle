<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;

final class FakeCollector implements CollectorInterface, BootableCollectorInterface, ResetInterface
{
    public int $boots = 0;
    public int $collects = 0;
    public int $resets = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $name,
        private array $data = [],
        private readonly ?\Throwable $bootFailure = null,
        private readonly ?\Throwable $collectFailure = null,
    ) {}

    public function boot(): void
    {
        $this->boots++;

        if ($this->bootFailure !== null) {
            throw $this->bootFailure;
        }
    }

    public function collect(): array
    {
        $this->collects++;

        if ($this->collectFailure !== null) {
            throw $this->collectFailure;
        }

        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function reset(): void
    {
        $this->resets++;
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
