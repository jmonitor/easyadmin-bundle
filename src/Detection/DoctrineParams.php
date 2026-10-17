<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

/**
 * Readings shared by the detectors built on the Doctrine DBAL connection parameters.
 */
final class DoctrineParams
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Without a host, the drivers connect to localhost; PostgreSQL also takes a socket directory as host.
     *
     * @param array<string, mixed> $params
     */
    public static function isLocal(array $params): bool
    {
        $params = is_array($params['primary'] ?? null) ? $params['primary'] : $params;

        if (is_string($params['unix_socket'] ?? null) && $params['unix_socket'] !== '') {
            return true;
        }

        $host = $params['host'] ?? null;

        if ($host === null || $host === '') {
            return true;
        }

        return is_string($host) && (str_starts_with($host, '/') || in_array(strtolower($host), self::LOCAL_HOSTS, true));
    }
}
