<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Doctrine\DBAL\Connection;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use Jmonitor\Utils\DatabaseAdapter\DoctrineAdapter;

/**
 * Shared by the collectors of a connection: once the server could not be reached, the next queries
 * fail at once instead of waiting for the connection timeout again. A query failing on a live
 * connection, a missing privilege for instance, leaves the others alone.
 */
final class FailFastDoctrineAdapter implements DatabaseAdapterInterface
{
    private readonly DoctrineAdapter $adapter;
    private ?UnreachableServerException $failure = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->adapter = new DoctrineAdapter($connection);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    public function fetchAllAssociative(string $query, array $params = []): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            return $this->adapter->fetchAllAssociative($query, $params);
        } catch (\Throwable $e) {
            if ($this->connection->isConnected()) {
                throw $e;
            }

            throw $this->failure = new UnreachableServerException($e->getMessage(), 0, $e);
        }
    }
}
