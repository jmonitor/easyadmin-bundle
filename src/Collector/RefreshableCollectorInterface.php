<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

/**
 * A collector keeping its own entries in the cache pool, besides the result SnapshotProvider
 * caches for it: the Refresh button drops them too.
 */
interface RefreshableCollectorInterface
{
    public function refresh(): void;
}
