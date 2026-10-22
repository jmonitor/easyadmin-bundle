<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Release\LatestRelease;

final readonly class PackageVersion
{
    public function __construct(
        public string $package,
        public ?string $installed,
        public ?Badge $badge = null,
        public ?string $url = null,
    ) {}

    /**
     * A development branch or a pre-release gets no badge.
     */
    public static function of(string $package, ?string $installed, ?LatestRelease $latest): self
    {
        if ($installed === null || $latest === null || preg_match('/^v?\d+\.\d+\.\d+$/', $installed) !== 1) {
            return new self($package, $installed);
        }

        if (version_compare(ltrim($installed, 'v'), $latest->version, '>=')) {
            return new self($package, $installed, new Badge(BadgeStyle::Success, 'Up to date'));
        }

        return new self($package, $installed, new Badge(BadgeStyle::Warning, $latest->version . ' available'), $latest->url);
    }
}
