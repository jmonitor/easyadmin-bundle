<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

final readonly class Recommendation
{
    /**
     * @param ?bool $ok null when the fact needed to decide is unknown
     */
    public function __construct(
        public string $label,
        public ?bool $ok,
        public ?string $value = null,
    ) {}
}
