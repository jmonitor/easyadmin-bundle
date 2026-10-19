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
use Jmonitor\EasyAdminBundle\Collector\FailFastDoctrineAdapter;
use Jmonitor\EasyAdminBundle\Collector\HostRamCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Psr\Container\ContainerInterface;

/**
 * Detection reads the connection parameters only and never opens the connection: a server
 * that is down shows up as a collect error inside the tab, exactly when an admin wants to look.
 */
final readonly class MysqlDetector implements ComponentDetectorInterface
{
    private const DRIVERS = ['pdo_mysql', 'mysqli'];

    /**
     * @param ContainerInterface                  $connections     Doctrine connections, keyed by connection name
     * @param list<string>                        $connectionNames
     * @param array<string, array<string, mixed>> $configured      the configured connections, empty to take every MySQL one
     * @param list<string>                        $exclude         patterns of the connection names left out when none is configured
     */
    public function __construct(
        private ContainerInterface $connections,
        private array $connectionNames,
        private array $configured,
        private string $osFamily = PHP_OS_FAMILY,
        private ?AdapterInterface $systemAdapter = null,
        private ?SkippedConnectionReporter $skipped = null,
        private array $exclude = [],
    ) {}

    public function component(): Component
    {
        return Component::Mysql;
    }

    public function detect(): Detection
    {
        return DoctrineParams::instances(
            Component::Mysql,
            $this->connections,
            $this->connectionNames,
            $this->configured,
            self::isMysql(...),
            fn(Connection $connection, array $params): CollectorSet => $this->collectors($connection, DoctrineParams::database($params) ?? '', DoctrineParams::isLocal($params)),
            'MySQL or MariaDB',
            $this->skipped,
            $this->exclude,
            byServer: true,
        );
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
        $adapter = new FailFastDoctrineAdapter($connection);
        $specs = [
            new CollectorSpec(new MysqlStatusCollector($adapter), Lifetime::Dynamic, trackDelta: true, perServer: true),
            new CollectorSpec(new MysqlVariablesCollector($adapter), Lifetime::Static, perServer: true),
            new CollectorSpec(new MysqlInformationSchemaCollector($adapter, $database), Lifetime::Static),
            new CollectorSpec(new MysqlSlowQueriesCollector($adapter, $database, 10, 1, 0, MysqlSlowQueriesCollector::ORDER_BY_AVG_TIME), Lifetime::Minute),
        ];

        if ($local && ($this->systemAdapter !== null || $this->osFamily === 'Linux')) {
            $specs[] = new CollectorSpec(new HostRamCollector($this->systemAdapter ?? new LinuxAdapter()), Lifetime::Static, perServer: true);
        }

        return new CollectorSet(Component::Mysql, $specs);
    }
}
