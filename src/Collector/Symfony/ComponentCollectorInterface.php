<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector\Symfony;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;

/**
 * @internal
 */
interface ComponentCollectorInterface
{
    /**
     * @throws BootFailedException
     */
    public function boot(): void;

    /**
     * @return array<mixed>
     *
     * @throws CollectorException
     */
    public function collect(): array;
}
