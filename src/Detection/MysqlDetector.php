<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\Collector\System\Adapter\LinuxAdapter;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\HostRamCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Utils\DatabaseAdapter\DoctrineAdapter;
use Psr\Container\ContainerInterface;

/**
 * Detection reads the connection parameters only and never opens the connection: a server
 * that is down shows up as a collect error inside the tab, exactly when an admin wants to look.
 */
final readonly class MysqlDetector implements ComponentDetectorInterface
{
    private const DRIVERS = ['pdo_mysql', 'mysqli'];
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * @param ContainerInterface $connections     Doctrine connections, keyed by connection name
     * @param list<string>       $connectionNames
     * @param ?string            $connection      the configured connection name, null to take the first MySQL one
     */
    public function __construct(
        private ContainerInterface $connections,
        private array $connectionNames,
        private ?string $connection,
        private string $osFamily = PHP_OS_FAMILY,
        private ?AdapterInterface $systemAdapter = null,
    ) {}

    public function component(): Component
    {
        return Component::Mysql;
    }

    public function detect(): Detection
    {
        if ($this->connectionNames === [] && $this->connection === null) {
            return Detection::unavailable('No Doctrine DBAL connection is configured.');
        }

        $candidates = $this->connection !== null ? [$this->connection] : $this->connectionNames;

        foreach ($candidates as $name) {
            if (!$this->connections->has($name)) {
                return Detection::unavailable(sprintf('Unknown Doctrine connection "%s".', $name));
            }

            /** @var Connection $connection */
            $connection = $this->connections->get($name);
            $params = $connection->getParams();

            if (!self::isMysql($params)) {
                if ($this->connection !== null) {
                    return Detection::unavailable(sprintf('Doctrine connection "%s" is not a MySQL or MariaDB connection.', $name));
                }

                continue;
            }

            $database = $params['dbname'] ?? null;

            return Detection::found($this->collectors($connection, is_string($database) ? $database : '', self::isLocal($params)));
        }

        return Detection::unavailable('No MySQL or MariaDB Doctrine connection found.');
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function isMysql(array $params): bool
    {
        $driverClass = $params['driverClass'] ?? null;

        if (is_string($driverClass) && class_exists($driverClass)) {
            return is_a($driverClass, AbstractMySQLDriver::class, true);
        }

        return in_array($params['driver'] ?? null, self::DRIVERS, true);
    }

    /**
     * Without a host, the drivers connect to localhost.
     *
     * @param array<string, mixed> $params
     */
    private static function isLocal(array $params): bool
    {
        $params = is_array($params['primary'] ?? null) ? $params['primary'] : $params;

        if (is_string($params['unix_socket'] ?? null) && $params['unix_socket'] !== '') {
            return true;
        }

        $host = $params['host'] ?? null;

        return $host === null || $host === '' || (is_string($host) && in_array(strtolower($host), self::LOCAL_HOSTS, true));
    }

    private function collectors(Connection $connection, string $database, bool $local): CollectorSet
    {
        $adapter = new DoctrineAdapter($connection);
        $specs = [
            new CollectorSpec(new MysqlStatusCollector($adapter), Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec(new MysqlVariablesCollector($adapter), Lifetime::Static),
            new CollectorSpec(new MysqlInformationSchemaCollector($adapter, $database), Lifetime::Static),
            new CollectorSpec(new MysqlSlowQueriesCollector($adapter, $database, 10, 1, 0, MysqlSlowQueriesCollector::ORDER_BY_AVG_TIME), Lifetime::Minute),
        ];

        if ($local && ($this->systemAdapter !== null || $this->osFamily === 'Linux')) {
            $specs[] = new CollectorSpec(new HostRamCollector($this->systemAdapter ?? new LinuxAdapter()), Lifetime::Static);
        }

        return new CollectorSet(Component::Mysql, $specs);
    }
}
