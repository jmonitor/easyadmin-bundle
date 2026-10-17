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

    /**
     * One instance per Doctrine connection: every connection matching the product when none is
     * configured, else exactly the configured ones, in error when they cannot be used.
     *
     * @param ContainerInterface                                       $connections     Doctrine connections, keyed by connection name
     * @param list<string>                                             $connectionNames every connection, in Doctrine order
     * @param array<string, array<string, mixed>>                      $configured      connection name => options, empty to take every matching connection
     * @param callable(array<string, mixed>): bool                     $matches         on the connection parameters
     * @param callable(Connection, array<string, mixed>): CollectorSet $collectors      given the connection and its configured options
     * @param string                                                   $product         for the error messages, e.g. "PostgreSQL"
     */
    public static function instances(Component $component, ContainerInterface $connections, array $connectionNames, array $configured, callable $matches, callable $collectors, string $product): Detection
    {
        if ($configured !== []) {
            $instances = [];

            foreach ($configured as $name => $options) {
                $instances[] = self::configured($component, $connections, $name, $options, $matches, $collectors, $product);
            }

            return Detection::instances($instances);
        }

        if ($connectionNames === []) {
            return Detection::unavailable('No Doctrine DBAL connection is configured.');
        }

        $instances = [];
        $unreadable = [];

        foreach ($connectionNames as $name) {
            try {
                /** @var Connection $connection */
                $connection = $connections->get($name);
            } catch (\Throwable $e) {
                $unreadable[$name] = $e->getMessage();

                continue;
            }

            $params = $connection->getParams();

            if ($matches($params)) {
                $instances[] = Instance::found($component, $collectors($connection, []), $name, self::database($params));
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
        $params = is_array($params['primary'] ?? null) ? $params['primary'] : $params;
        $database = $params['dbname'] ?? null;

        return is_string($database) && $database !== '' ? $database : null;
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

    /**
     * @param array<string, mixed>                                     $options
     * @param callable(array<string, mixed>): bool                     $matches
     * @param callable(Connection, array<string, mixed>): CollectorSet $collectors
     */
    private static function configured(Component $component, ContainerInterface $connections, string $name, array $options, callable $matches, callable $collectors, string $product): Instance
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

        return Instance::found($component, $collectors($connection, $options), $name, self::database($params));
    }
}
