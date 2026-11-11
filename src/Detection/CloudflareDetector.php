<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\Collector\Cloudflare\Block\BlockInterface;
use Jmonitor\Collector\Cloudflare\Block\ImagesBlock;
use Jmonitor\Collector\Cloudflare\Block\R2Block;
use Jmonitor\Collector\Cloudflare\Block\TodayBlock;
use Jmonitor\Collector\Cloudflare\Block\TrafficBlock;
use Jmonitor\Collector\Cloudflare\Block\WebAnalyticsBlock;
use Jmonitor\Collector\Cloudflare\Block\ZoneBlock;
use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\EasyAdminBundle\Collector\CloudflareBlockCollector;
use Jmonitor\EasyAdminBundle\Collector\CloudflareZoneLookup;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\FailFastHttpClient;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Exceptions\MissingHttpClientException;
use Jmonitor\Utils\HttpDiscovery;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare is found when a token and a zone are configured. The zone is looked up when the
 * collectors are built, on the first collect: a refused token or an unknown zone is one error
 * for the whole tab, not one per block.
 */
final readonly class CloudflareDetector implements ComponentDetectorInterface
{
    private const HTTP_TIMEOUT = 5.0;

    /** Minutes of per-minute traffic read, of which the latest one that reached the origin is shown. */
    private const LOOKBACK = 5;

    /** Minutes covered by the traffic breakdowns. */
    private const WINDOW = 15;

    public function __construct(
        private ?string $apiToken,
        private ?string $zone,
        private CacheItemPoolInterface $cache,
        private int $staticTtl,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?ClientInterface $httpClient = null,
    ) {}

    public function component(): Component
    {
        return Component::Cloudflare;
    }

    public function detect(): Detection
    {
        $token = trim($this->apiToken ?? '');
        $zone = trim($this->zone ?? '');

        if ($token === '' || $zone === '') {
            return Detection::unavailable('No Cloudflare zone configured: set components.cloudflare.api_token and components.cloudflare.zone.');
        }

        $collectors = CollectorSet::lazy(Component::Cloudflare, fn(): CollectorSet => $this->collectors($token, $zone));

        return Detection::instances([Instance::found(Component::Cloudflare, $collectors, detail: $zone)]);
    }

    private function collectors(string $token, string $zone): CollectorSet
    {
        try {
            $inner = $this->httpClient ?? HttpDiscovery::findHttpClient(self::HTTP_TIMEOUT);
            $factory = HttpDiscovery::messageFactoryFor($inner);
        } catch (MissingHttpClientException) {
            throw new \RuntimeException('The Cloudflare tab needs an HTTP client: run "composer require symfony/http-client nyholm/psr7".');
        }

        $client = new FailFastHttpClient($inner);
        $api = new CloudflareApi($client, $factory, $token);
        $lookup = new CloudflareZoneLookup($zone, $this->cache, $this->staticTtl);
        $cloudflareZone = $lookup->find($api);

        $spec = fn(BlockInterface $block, Lifetime $lifetime): CollectorSpec => new CollectorSpec(
            new CloudflareBlockCollector($block, $api, $cloudflareZone, $client, $lookup, $this->clock, $this->logger),
            $lifetime,
        );

        return new CollectorSet(Component::Cloudflare, [
            $spec(new ZoneBlock(), Lifetime::Static),
            $spec(new TrafficBlock(self::LOOKBACK, self::WINDOW), Lifetime::Minute),
            $spec(new TodayBlock(), Lifetime::FiveMinutes),
            $spec(new WebAnalyticsBlock(), Lifetime::FiveMinutes),
            $spec(new R2Block(), Lifetime::QuarterHour),
            $spec(new ImagesBlock(), Lifetime::QuarterHour),
        ]);
    }
}
