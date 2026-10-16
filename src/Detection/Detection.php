<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;

final readonly class Detection
{
    private function __construct(
        public ?CollectorSet $collectors,
        public ?string $error,
    ) {}

    public static function found(CollectorSet $collectors): self
    {
        return new self($collectors, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isFound(): bool
    {
        return $this->collectors !== null;
    }
}
