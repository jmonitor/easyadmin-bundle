<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

final readonly class OverviewInstance
{
    public function __construct(
        public string $label,
        public ?string $title,
        public bool $error,
    ) {}
}
