<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

/**
 * The database server a connection reaches, read from its parameters: connections to the same
 * server share its server-wide metrics.
 */
final readonly class Server
{
    public function __construct(
        public string $key,
        public string $host,
        public ?int $port,
    ) {}
}
