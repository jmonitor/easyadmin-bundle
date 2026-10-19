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
use Jmonitor\EasyAdminBundle\Collector\FailFastDoctrineAdapter;
use Jmonitor\EasyAdminBundle\Collector\HostRamCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;

/**
 * Detection reads the connection parameters only and never opens the connection: a server
 * that is down shows up as a collect error inside the tab, exactly when an admin wants to look.
 */
final readonly class PostgresqlDetector implements ComponentDetectorInterface
{
    private const DRIVERS = ['pdo_pgsql', 'pgsql'];

    /**
     * @param array<string, array<string, mixed>> $configured the configured connections with their options, empty to take every PostgreSQL one
     * @param string                              $schema     schema whose tables feed the database collector, for the connections without their own
     * @param list<string>                        $exclude    patterns of the connection names left out when none is configured
     */
    public function __construct(
        private DoctrineConnections $connections,
        private array $configured,
        private string $schema = 'public',
        private string $osFamily = PHP_OS_FAMILY,
        private ?AdapterInterface $systemAdapter = null,
        private ?SkippedConnectionReporter $skipped = null,
        private array $exclude = [],
    ) {}

    public function component(): Component
    {
        return Component::Postgresql;
    }

    public function detect(): Detection
    {
        return DoctrineParams::instances(
            Component::Postgresql,
            $this->connections,
            $this->configured,
            self::isPostgresql(...),
            fn(Connection $connection, array $params, array $options): CollectorSet => $this->collectors($connection, self::schema($options) ?? $this->schema, DoctrineParams::isLocal($params)),
            'PostgreSQL',
            $this->skipped,
            $this->exclude,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function schema(array $options): ?string
    {
        $schema = $options['schema'] ?? null;

        return is_string($schema) && $schema !== '' ? $schema : null;
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

    private function collectors(Connection $connection, string $schema, bool $local): CollectorSet
    {
        $adapter = new FailFastDoctrineAdapter($connection);
        $specs = [
            new CollectorSpec(new PostgresqlActivityCollector($adapter), Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec(new PostgresqlSettingsCollector($adapter), Lifetime::Static),
            new CollectorSpec(new PostgresqlDatabaseCollector($adapter, $schema), Lifetime::Minute, trackDelta: true),
            new CollectorSpec(new PostgresqlSlowQueriesCollector($adapter, 10, 1, 0.0, PostgresqlSlowQueriesCollector::ORDER_BY_AVG_TIME, false), Lifetime::Minute),
        ];

        if ($local && ($this->systemAdapter !== null || $this->osFamily === 'Linux')) {
            $specs[] = new CollectorSpec(new HostRamCollector($this->systemAdapter ?? new LinuxAdapter()), Lifetime::Static);
        }

        return new CollectorSet(Component::Postgresql, $specs, $adapter->release(...));
    }
}
