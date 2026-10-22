<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Release;

interface ReleaseSourceInterface
{
    /**
     * @param string $repository "owner/name"
     *
     * @throws \Throwable when the release cannot be read
     */
    public function latest(string $repository): LatestRelease;
}
