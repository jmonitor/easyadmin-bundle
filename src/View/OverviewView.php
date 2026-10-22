<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

final readonly class OverviewView
{
    /**
     * @param list<OverviewComponent> $components         every component, the available ones first, each group in the order of the tabs
     * @param list<PackageVersion>    $versions
     * @param array<string, string>   $skippedConnections Doctrine connection name => error
     */
    public function __construct(
        public array $components,
        public array $versions,
        public int $pollingInterval,
        public int $staticTtl,
        public string $cachePool,
        public ?string $permission,
        public array $skippedConnections,
    ) {}
}
