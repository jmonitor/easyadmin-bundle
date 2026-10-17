<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\FrankenPhp\FrankenPhpCollector;
use Jmonitor\Exceptions\JmonitorException;

/**
 * Turns an unreachable Caddy endpoint, or an export without FrankenPHP metrics, into an error
 * that says how to enable them. The cause stays chained for SnapshotProvider::describe().
 */
final readonly class FrankenPhpEndpointCollector implements CollectorInterface
{
    public function __construct(
        private FrankenPhpCollector $inner,
        private string $endpoint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            $payload = $this->inner->collect();
        } catch (JmonitorException|\RuntimeException $e) {
            throw new \RuntimeException(sprintf('Cannot read the FrankenPHP metrics at %s. Enable them with the "metrics" global option of the Caddyfile and check "components.frankenphp.endpoint".', $this->endpoint), 0, $e);
        }

        if (($payload['mode'] ?? null) === null) {
            throw new \RuntimeException(sprintf('No FrankenPHP metrics at %s. Enable them with the "metrics" global option of the Caddyfile.', $this->endpoint));
        }

        return $payload;
    }

    public function getVersion(): int
    {
        return $this->inner->getVersion();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }
}
