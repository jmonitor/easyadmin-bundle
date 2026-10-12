<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Utils\DatabaseAdapter\DoctrineAdapter;
use Psr\Container\ContainerInterface;

final readonly class MysqlDetector implements ComponentDetectorInterface
{
    /**
     * @param ContainerInterface $connections     Doctrine connections, keyed by connection name
     * @param list<string>       $connectionNames
     * @param ?string            $connection      the configured connection name, null to take the first MySQL one
     */
    public function __construct(
        private ContainerInterface $connections,
        private array $connectionNames,
        private ?string $connection,
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
        $lastError = null;

        foreach ($candidates as $name) {
            if (!$this->connections->has($name)) {
                return Detection::unavailable(sprintf('Unknown Doctrine connection "%s".', $name));
            }

            /** @var Connection $connection */
            $connection = $this->connections->get($name);

            try {
                $platform = $connection->getDatabasePlatform();
                $database = $connection->getDatabase();
            } catch (\Throwable $e) {
                $lastError = sprintf('Connection "%s": %s', $name, $e->getMessage());

                continue;
            }

            if (!$platform instanceof AbstractMySQLPlatform) {
                if ($this->connection !== null) {
                    return Detection::unavailable(sprintf('Doctrine connection "%s" is not a MySQL or MariaDB connection.', $name));
                }

                continue;
            }

            return Detection::found($this->collectors($connection, $database ?? ''));
        }

        return Detection::unavailable($lastError ?? 'No MySQL or MariaDB Doctrine connection found.');
    }

    private function collectors(Connection $connection, string $database): CollectorSet
    {
        $adapter = new DoctrineAdapter($connection);

        return new CollectorSet(Component::Mysql, [
            new CollectorSpec(new MysqlStatusCollector($adapter), Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec(new MysqlVariablesCollector($adapter), Lifetime::Static),
            new CollectorSpec(new MysqlInformationSchemaCollector($adapter, $database), Lifetime::Static),
            new CollectorSpec(new MysqlSlowQueriesCollector($adapter, $database, 10, 1, 0, MysqlSlowQueriesCollector::ORDER_BY_AVG_TIME), Lifetime::Minute),
        ]);
    }
}
