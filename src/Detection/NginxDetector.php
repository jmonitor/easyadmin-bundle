<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\LoggingShellExecutor;
use Jmonitor\EasyAdminBundle\Collector\NginxConfigCollector;
use Jmonitor\EasyAdminBundle\Collector\NginxStatusCollector;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;
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
            return Detection::unavailable('Nginx is not serving this page.');
        }

        $shell = new LoggingShellExecutor($this->logger, $this->shellExecutor);

        return Detection::found(new CollectorSet(Component::Nginx, [
            new CollectorSpec(new NginxStatusCollector($this->endpoint, $this->urlFetcher), Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec(new NginxConfigCollector($shell, ($m[1] ?? '') !== '' ? $m[1] : null), Lifetime::Static),
        ]));
    }
}
