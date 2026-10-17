<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;

final readonly class Detection
{
    /**
     * @param list<Instance> $instances
     * @param ?string        $error     why there is no instance at all
     */
    private function __construct(
        public array $instances,
        public ?string $error,
    ) {}

    public static function found(CollectorSet $collectors): self
    {
        return new self([Instance::found($collectors->component, $collectors)], null);
    }

    /**
     * @param non-empty-list<Instance> $instances
     */
    public static function instances(array $instances): self
    {
        return new self($instances, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self([], $reason);
    }

    public function isFound(): bool
    {
        return $this->instances !== [];
    }
}
