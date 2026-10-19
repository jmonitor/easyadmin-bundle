<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Detection\Instance;

/**
 * One pill of the row under the tabs; it links to its target instance.
 */
final readonly class NavigationItem
{
    public function __construct(
        public string $label,
        public ?string $detail,
        public Instance $target,
        public bool $active,
        public ?string $error,
    ) {}
}
