<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\Cloudflare\Block\BlockInterface;
use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Jmonitor\Collector\CollectorInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * One block of the Cloudflare payload as a collector, so that each block has its own lifetime.
 * A block the token cannot read, or a product the account does not use, is not an error: the
 * payload is {unavailable: reason}, kept as long as the data would be. Any other failure is
 * kept longer than a poll: Cloudflare rate limits its API.
 */
final readonly class CloudflareBlockCollector implements CollectorInterface, RefreshableCollectorInterface
{
    /** Seconds Cloudflare needs to publish a minute of analytics. */
    private const LAG = 180;

    public function __construct(
        private BlockInterface $block,
        private CloudflareApi $api,
        private CloudflareZone $zone,
        private FailFastHttpClient $client,
        private CloudflareZoneLookup $lookup,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $to = intdiv($this->clock->now()->getTimestamp() - self::LAG, 60) * 60;

        try {
            $data = $this->block->fetch($this->api, $this->zone, $to, $this->logger);
        } catch (CloudflareException $e) {
            if (in_array($e->getReason(), [CloudflareException::FORBIDDEN, CloudflareException::NOT_CONFIGURED], true)) {
                return ['unavailable' => $e->getReason()];
            }

            throw UnreachableServerException::withRootCause($this->failure(), $e);
        }

        // A payload read through a failed request would miss keys for its whole lifetime.
        if ($this->client->hasFailed()) {
            throw new UnreachableServerException($this->failure());
        }

        return $data;
    }

    public function refresh(): void
    {
        $this->lookup->forget();
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'cloudflare.' . $this->block->getName();
    }

    private function failure(): string
    {
        return sprintf('Cannot read the Cloudflare "%s" data.', $this->block->getName());
    }
}
