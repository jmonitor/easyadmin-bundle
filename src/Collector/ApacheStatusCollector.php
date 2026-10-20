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
 * for SnapshotProvider::describe(). The failure is an UnreachableServerException, kept longer than
 * a poll: the default endpoint is the web server itself, which would boot the application again
 * at each poll to answer that the page does not exist.
 *
 * The payload adds "total_duration" (seconds) and keeps "duration_per_request" (milliseconds) as a
 * float, which ApacheCollector rounds to an integer.
 */
final readonly class ApacheStatusCollector implements CollectorInterface
{
    private ModStatusFetcher $fetcher;
    private ApacheCollector $inner;

    public function __construct(
        private string $endpoint,
        ?UrlFetcher $urlFetcher = null,
    ) {
        $this->fetcher = new ModStatusFetcher($urlFetcher ?? new UrlFetcher());
        $this->inner = new ApacheCollector($endpoint, $this->fetcher);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            $payload = $this->inner->collect();
        } catch (JmonitorException|\RuntimeException $e) {
            $cause = $e;

            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            throw new UnreachableServerException(sprintf('Cannot read the Apache status at %s. Enable mod_status with a "SetHandler server-status" location reachable from PHP and check "components.apache.endpoint".', $this->endpoint), 0, $cause);
        }

        $totalDuration = $this->fetcher->value('Total Duration');
        $payload['total_duration'] = $totalDuration === null ? null : $totalDuration / 1000;
        $payload['duration_per_request'] = $this->fetcher->value('DurationPerReq');

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
