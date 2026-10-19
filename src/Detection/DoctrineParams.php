<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Doctrine\DBAL\Connection;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Psr\Container\ContainerInterface;

/**
 * Readings shared by the detectors built on the Doctrine DBAL connection parameters.
 */
final class DoctrineParams
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];
    private const NO_DBAL = 'No Doctrine DBAL connection is configured.';

    /**
     * One instance per Doctrine connection: every connection matching the product when none is
     * configured, else exactly the configured ones, in error when they cannot be used. An excluded
     * connection is never built: building one may open it, to read the server version.
     *
     * @param ContainerInterface                                       $connections     Doctrine connections, keyed by connection name
     * @param list<string>                                             $connectionNames every connection, in Doctrine order
     * @param array<string, array<string, mixed>>                      $configured      connection name => options, empty to take every matching connection
     * @param callable(array<string, mixed>): bool                     $matches         on the connection parameters
     * @param callable(Connection, array<string, mixed>, array<string, mixed>): CollectorSet $collectors given the connection, its parameters and its configured options
     * @param string                                                   $product         for the error messages, e.g. "PostgreSQL"
     * @param ?SkippedConnectionReporter                               $skipped         told of the connections skipped because they cannot be read
     * @param list<string>                                             $exclude         fnmatch() patterns of the connection names left out when none is configured
     * @param bool                                                     $byServer        attaches each found instance to the server of its connection
     */
    public static function instances(Component $component, ContainerInterface $connections, array $connectionNames, array $configured, callable $matches, callable $collectors, string $product, ?SkippedConnectionReporter $skipped = null, array $exclude = [], bool $byServer = false): Detection
    {
        if ($configured !== []) {
            $instances = [];

            foreach ($configured as $name => $options) {
                $name = (string) $name;
                $instances[] = $connectionNames === []
                    ? Instance::unavailable($component, self::NO_DBAL, $name)
                    : self::configured($component, $connections, $name, $options, $matches, $collectors, $product, $byServer);
            }

            return Detection::instances($instances);
        }

        if ($connectionNames === []) {
            return Detection::unavailable(self::NO_DBAL);
        }

        $instances = [];
        $unreadable = [];

        foreach ($connectionNames as $name) {
            $name = (string) $name;

            if (self::isExcluded($name, $exclude)) {
                continue;
            }

            try {
                /** @var Connection $connection */
                $connection = $connections->get($name);
            } catch (\Throwable $e) {
                $unreadable[$name] = $e->getMessage();
                $skipped?->report($name, $e);

                continue;
            }

            $params = $connection->getParams();

            if ($matches($params)) {
                $instances[] = Instance::found($component, $collectors($connection, $params, []), $name, self::database($params), $byServer ? self::server($params) : null);
            }
        }

        if ($instances === []) {
            $error = sprintf('No %s Doctrine connection found.', $product);

            foreach ($unreadable as $name => $message) {
                $error .= sprintf(' Connection "%s" could not be read: %s', $name, $message);
            }

            return Detection::unavailable($error);
        }

        return Detection::instances($instances);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function database(array $params): ?string
    {
        $database = self::primary($params)['dbname'] ?? null;

        return is_string($database) && $database !== '' ? $database : null;
    }

    /**
     * Without a host, the drivers connect to localhost; PostgreSQL also takes a socket directory as host.
     *
     * @param array<string, mixed> $params
     */
    public static function isLocal(array $params): bool
    {
        $params = self::primary($params);

        if (self::socket($params) !== null) {
            return true;
        }

        $host = $params['host'] ?? null;

        if ($host === null || $host === '') {
            return true;
        }

        return is_string($host) && (str_starts_with($host, '/') || in_array(strtolower($host), self::LOCAL_HOSTS, true));
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function server(array $params): Server
    {
        $params = self::primary($params);
        $socket = self::socket($params);

        if ($socket !== null) {
            return new Server('socket:' . $socket, 'localhost', null, $socket);
        }

        $host = $params['host'] ?? null;
        $host = is_string($host) && $host !== '' ? strtolower($host) : 'localhost';
        $port = $params['port'] ?? null;
        $port = is_int($port) || (is_string($port) && ctype_digit($port)) ? (int) $port : 3306;

        return new Server($host . ':' . $port, $host, $port);
    }

    /**
     * @param list<string> $patterns
     */
    private static function isExcluded(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parameters of the server written to: those of the primary when the connection has replicas.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function primary(array $params): array
    {
        return is_array($params['primary'] ?? null) ? $params['primary'] : $params;
    }

    /**
     * The MySQL drivers only use the socket without a host or with "localhost", TCP otherwise.
     *
     * @param array<string, mixed> $params
     */
    private static function socket(array $params): ?string
    {
        $socket = $params['unix_socket'] ?? null;
        $host = $params['host'] ?? null;

        if (!is_string($socket) || $socket === '') {
            return null;
        }

        return $host === null || $host === '' || (is_string($host) && strtolower($host) === 'localhost') ? $socket : null;
    }

    /**
     * @param array<string, mixed>                                                           $options
     * @param callable(array<string, mixed>): bool                                           $matches
     * @param callable(Connection, array<string, mixed>, array<string, mixed>): CollectorSet $collectors
     */
    private static function configured(Component $component, ContainerInterface $connections, string $name, array $options, callable $matches, callable $collectors, string $product, bool $byServer): Instance
    {
        if (!$connections->has($name)) {
            return Instance::unavailable($component, sprintf('Unknown Doctrine connection "%s".', $name), $name);
        }

        try {
            /** @var Connection $connection */
            $connection = $connections->get($name);
        } catch (\Throwable $e) {
            return Instance::unavailable($component, $e->getMessage(), $name);
        }

        $params = $connection->getParams();

        if (!$matches($params)) {
            return Instance::unavailable($component, sprintf('Doctrine connection "%s" is not a %s connection.', $name, $product), $name, self::database($params));
        }

        return Instance::found($component, $collectors($connection, $params, $options), $name, self::database($params), $byServer ? self::server($params) : null);
    }
}
