<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Release;

use Jmonitor\EasyAdminBundle\Release\LatestRelease;
use Jmonitor\EasyAdminBundle\Release\ReleaseSourceInterface;

/**
 * Answers every repository with the same release, or fails like an unreachable GitHub when there is none.
 */
final class FixtureReleaseSource implements ReleaseSourceInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?LatestRelease $release,
    ) {}

    public function latest(string $repository): LatestRelease
    {
        $this->calls++;

        return $this->release ?? throw new \RuntimeException(sprintf('Failed to fetch the latest release of "%s".', $repository));
    }
}
