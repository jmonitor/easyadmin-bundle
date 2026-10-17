<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;

/**
 * Readings shared by the detectors built on the Doctrine DBAL connection parameters.
 */
final class DoctrineParams
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * The configured connection when there is one, else the first whose parameters match.
     *
     * @param ContainerInterface                     $connections     Doctrine connections, keyed by connection name
     * @param list<string>                           $connectionNames
     * @param ?string                                $connection      the configured connection name
     * @param callable(array<string, mixed>): bool   $matches
     * @param string                                 $product         for the error messages, e.g. "PostgreSQL"
     *
     * @return Connection|Detection the connection, or the unavailable detection explaining why there is none
     */
    public static function find(ContainerInterface $connections, array $connectionNames, ?string $connection, callable $matches, string $product): Connection|Detection
    {
        if ($connectionNames === [] && $connection === null) {
            return Detection::unavailable('No Doctrine DBAL connection is configured.');
        }

        foreach ($connection !== null ? [$connection] : $connectionNames as $name) {
            if (!$connections->has($name)) {
                return Detection::unavailable(sprintf('Unknown Doctrine connection "%s".', $name));
            }

            /** @var Connection $candidate */
            $candidate = $connections->get($name);

            if ($matches($candidate->getParams())) {
                return $candidate;
            }

            if ($connection !== null) {
                return Detection::unavailable(sprintf('Doctrine connection "%s" is not a %s connection.', $name, $product));
            }
        }

        return Detection::unavailable(sprintf('No %s Doctrine connection found.', $product));
    }

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
