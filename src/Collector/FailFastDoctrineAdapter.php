<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * Shared by the collectors of a connection: once the server could not be reached, the next queries
 * fail at once instead of waiting for the connection timeout again. A query failing on a live
 * connection, a missing privilege for instance, leaves the others alone.
 */
final class FailFastDoctrineAdapter implements DatabaseAdapterInterface
{
    private ?UnreachableServerException $failure = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

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

        $wasConnected = $this->isConnected();

        try {
            try {
                // reads would go to a replica, not to the primary the server is identified by
                if ($this->connection instanceof PrimaryReadReplicaConnection && !$wasConnected) {
                    $this->connection->ensureConnectedToPrimary();
                }

                return $this->connection->fetchAllAssociative($query, $params);
            } catch (ConnectionLost $e) {
                // An open connection the server dropped meanwhile ("gone away") reconnects; a new one would wait again.
                if (!$wasConnected) {
                    throw $e;
                }

                return $this->connection->fetchAllAssociative($query, $params);
            }
        } catch (\Throwable $e) {
            if ($this->isConnected()) {
                throw $e;
            }

            throw $this->failure = new UnreachableServerException($e->getMessage(), 0, $e);
        }
    }

    private function isConnected(): bool
    {
        return $this->connection instanceof PrimaryReadReplicaConnection ? $this->connection->isConnectedToPrimary() : $this->connection->isConnected();
    }
}
