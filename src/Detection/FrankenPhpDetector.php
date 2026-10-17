<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\Collector\FrankenPhp\FrankenPhpCollector;
use Jmonitor\EasyAdminBundle\Collector\CachedShellExecutor;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\FrankenPhpEndpointCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * FrankenPHP is detected from the SAPI of the process serving the page. The SAPI is read when the
 * service is built, not when the container is compiled: "cache:clear" runs under the CLI SAPI.
 */
final readonly class FrankenPhpDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $endpoint,
        private CacheItemPoolInterface $cache,
        private int $staticTtl,
        private LoggerInterface $logger,
        private string $sapi = PHP_SAPI,
        private ?UrlFetcher $urlFetcher = null,
        private ?ShellExecutor $shellExecutor = null,
    ) {}

    public function component(): Component
    {
        return Component::FrankenPhp;
    }

    public function detect(): Detection
    {
        if ($this->sapi !== 'frankenphp') {
            return Detection::unavailable(sprintf('FrankenPHP is not serving this page (PHP SAPI "%s").', $this->sapi));
        }

        $shell = new CachedShellExecutor($this->cache, $this->staticTtl, Component::FrankenPhp->value, $this->logger, $this->shellExecutor);
        $collector = new FrankenPhpCollector(new PrometheusMetricsProvider($this->endpoint, $this->urlFetcher), $shell);

        return Detection::found(new CollectorSet(Component::FrankenPhp, [
            new CollectorSpec(new FrankenPhpEndpointCollector($collector, $this->endpoint, $shell), Lifetime::Dynamic, trackDelta: true),
        ]));
    }
}
