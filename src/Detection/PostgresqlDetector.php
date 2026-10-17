<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Jmonitor\Collector\Postgresql\PostgresqlActivityCollector;
use Jmonitor\Collector\Postgresql\PostgresqlDatabaseCollector;
use Jmonitor\Collector\Postgresql\PostgresqlSettingsCollector;
use Jmonitor\Collector\Postgresql\PostgresqlSlowQueriesCollector;
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
final readonly class PostgresqlDetector implements ComponentDetectorInterface
{
    private const DRIVERS = ['pdo_pgsql', 'pgsql'];

    /**
     * @param ContainerInterface $connections     Doctrine connections, keyed by connection name
     * @param list<string>       $connectionNames
     * @param ?string            $connection      the configured connection name, null to take the first PostgreSQL one
     * @param string             $schema          schema whose tables feed the database collector
     */
    public function __construct(
        private ContainerInterface $connections,
        private array $connectionNames,
        private ?string $connection,
        private string $schema = 'public',
        private string $osFamily = PHP_OS_FAMILY,
        private ?AdapterInterface $systemAdapter = null,
    ) {}

    public function component(): Component
    {
        return Component::Postgresql;
    }

    public function detect(): Detection
    {
        $connection = DoctrineParams::find($this->connections, $this->connectionNames, $this->connection, self::isPostgresql(...), 'PostgreSQL');

        if ($connection instanceof Detection) {
            return $connection;
        }

        return Detection::found($this->collectors($connection, DoctrineParams::isLocal($connection->getParams())));
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function isPostgresql(array $params): bool
    {
        $driverClass = $params['driverClass'] ?? null;

        if (is_string($driverClass) && class_exists($driverClass)) {
            return is_a($driverClass, AbstractPostgreSQLDriver::class, true);
        }

        return in_array($params['driver'] ?? null, self::DRIVERS, true);
    }

    private function collectors(Connection $connection, bool $local): CollectorSet
    {
        $adapter = new DoctrineAdapter($connection);
        $specs = [
            new CollectorSpec(new PostgresqlActivityCollector($adapter), Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec(new PostgresqlSettingsCollector($adapter), Lifetime::Static),
            new CollectorSpec(new PostgresqlDatabaseCollector($adapter, $this->schema), Lifetime::Minute, trackDelta: true),
            new CollectorSpec(new PostgresqlSlowQueriesCollector($adapter, 10, 1, 0.0, PostgresqlSlowQueriesCollector::ORDER_BY_AVG_TIME, false), Lifetime::Minute),
        ];

        if ($local && ($this->systemAdapter !== null || $this->osFamily === 'Linux')) {
            $specs[] = new CollectorSpec(new HostRamCollector($this->systemAdapter ?? new LinuxAdapter()), Lifetime::Static);
        }

        return new CollectorSet(Component::Postgresql, $specs);
    }
}
