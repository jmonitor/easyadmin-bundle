<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Utils\UrlFetcher;

/**
 * Turns an unreachable status page, or a page that is not mod_status, into an error that says how
 * to expose it. It is an UnreachableServerException: the default endpoint is the web server itself,
 * which would boot the application again to answer that the page does not exist.
 *
 * The payload gives "total_duration" in seconds.
 */
final readonly class ApacheStatusCollector implements CollectorInterface
{
    private ApacheCollector $inner;

    public function __construct(
        private string $endpoint,
        ?UrlFetcher $urlFetcher = null,
    ) {
        $this->inner = new ApacheCollector($endpoint, $urlFetcher);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            $payload = $this->inner->collect();
        } catch (JmonitorException|\RuntimeException $e) {
            throw UnreachableServerException::withRootCause(sprintf('Cannot read the Apache status at %s. Enable mod_status with a "SetHandler server-status" location reachable from PHP and check "components.apache.endpoint".', $this->endpoint), $e);
        }

        if (isset($payload['total_duration'])) {
            $payload['total_duration'] /= 1000.0;
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
