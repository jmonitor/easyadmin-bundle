<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\ApacheStatusCollector;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\Utils\UrlFetcher;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Apache is detected from the SAPI of mod_php, or from the SERVER_SOFTWARE it passes to PHP-FPM.
 * The SAPI is read when the service is built, not when the container is compiled: "cache:clear"
 * runs under the CLI SAPI.
 */
final readonly class ApacheDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $endpoint,
        private RequestStack $requestStack,
        private string $sapi = PHP_SAPI,
        private ?UrlFetcher $urlFetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::Apache;
    }

    public function detect(): Detection
    {
        $software = $this->requestStack->getMainRequest()?->server->get('SERVER_SOFTWARE');
        $software = is_string($software) && $software !== '' ? $software : null;

        if ($this->sapi !== 'apache2handler' && !str_starts_with($software ?? '', 'Apache')) {
            return Detection::unavailable(sprintf('Apache is not serving this page (PHP SAPI "%s", server software "%s").', $this->sapi, $software ?? 'unknown'));
        }

        return Detection::found(new CollectorSet(Component::Apache, [
            new CollectorSpec(new ApacheStatusCollector($this->endpoint, $this->urlFetcher), Lifetime::Dynamic, trackDelta: true),
        ]));
    }
}
