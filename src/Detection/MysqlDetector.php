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
        $connection = DoctrineParams::find($this->connections, $this->connectionNames, $this->connection, self::isMysql(...), 'MySQL or MariaDB');

        if ($connection instanceof Detection) {
            return $connection;
        }

        $params = $connection->getParams();
        $database = $params['dbname'] ?? null;

        return Detection::found($this->collectors($connection, is_string($database) ? $database : '', DoctrineParams::isLocal($params)));
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
