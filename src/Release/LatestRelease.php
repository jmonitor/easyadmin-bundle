<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Release;

final readonly class LatestRelease
{
    public function __construct(
        public string $version,
        public string $url,
    ) {}
}
