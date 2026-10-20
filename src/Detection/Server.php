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
        public ?string $socket = null,
    ) {}

    public function address(): string
    {
        if ($this->socket !== null) {
            return $this->socket;
        }

        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? '[' . $this->host . ']' : $this->host;

        return $this->port === null ? $host : $host . ':' . $this->port;
    }
}
