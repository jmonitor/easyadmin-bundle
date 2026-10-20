<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Utils\UrlFetcher;

/**
 * Turns an unreachable status page, or a page that is not mod_status, into an error that says how
 * to expose it. ApacheCollector wraps the failure of the fetch: its root cause is the one chained
 * for SnapshotProvider::describe().
 */
final readonly class ApacheStatusCollector implements CollectorInterface
{
    private ApacheCollector $inner;

    public function __construct(
        private string $endpoint,
        ?UrlFetcher $urlFetcher = null,
    ) {
        $this->inner = new ApacheCollector($endpoint, new ModStatusFetcher($urlFetcher ?? new UrlFetcher()));
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            return $this->inner->collect();
        } catch (JmonitorException|\RuntimeException $e) {
            $cause = $e;

            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            throw new \RuntimeException(sprintf('Cannot read the Apache status at %s. Enable mod_status with a "SetHandler server-status" location reachable from PHP and check "components.apache.endpoint".', $this->endpoint), 0, $cause);
        }
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
