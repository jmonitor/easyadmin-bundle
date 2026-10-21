<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\EasyAdminBundle\Collector\CachedShellExecutor;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\NginxStatusCollector;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nginx is detected from the SERVER_SOFTWARE it passes to PHP-FPM: "nginx/<version>" in the
 * fastcgi_params it ships.
 */
final readonly class NginxDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $endpoint,
        private RequestStack $requestStack,
        private CacheItemPoolInterface $cache,
        private int $staticTtl,
        private LoggerInterface $logger,
        private ?UrlFetcher $urlFetcher = null,
        private ?ShellExecutor $shellExecutor = null,
    ) {}

    public function component(): Component
    {
        return Component::Nginx;
    }

    public function detect(): Detection
    {
        $software = $this->requestStack->getMainRequest()?->server->get('SERVER_SOFTWARE');
        $software = is_string($software) && $software !== '' ? $software : null;

        if (preg_match('~^nginx(?:/(\S+)|\s|$)~i', $software ?? '', $m) !== 1) {
            return Detection::unavailable(sprintf('Nginx is not serving this page (server software "%s").', $software ?? 'unknown'));
        }

        $shell = new CachedShellExecutor($this->cache, $this->staticTtl, Component::Nginx->value, $this->logger, $this->shellExecutor);
        $collector = new NginxCollector($this->endpoint, $shell, $this->urlFetcher);

        return Detection::found(new CollectorSet(Component::Nginx, [
            new CollectorSpec(new NginxStatusCollector($collector, $this->endpoint, $shell, ($m[1] ?? '') !== '' ? $m[1] : null), Lifetime::Dynamic, trackDelta: true),
        ]));
    }
}
