<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Versions an asset by a hash of its source file in the bundle, so that upgrading the bundle changes its URL.
 */
final readonly class ContentHashVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        private string $directory,
    ) {}

    public function getVersion(string $path): string
    {
        $file = $this->directory . '/' . $path;
        $hash = is_file($file) ? hash_file('xxh128', $file) : false;

        return $hash === false ? '' : substr($hash, 0, 8);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return $version === '' ? $path : $path . '?v=' . $version;
    }
}
