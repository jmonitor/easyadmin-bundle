<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\UrlFetcher;

/**
 * Refuses a page that is not the machine-readable report of mod_status, such as the application
 * answering in its place: ApacheCollector needs its Scoreboard line.
 */
final class ModStatusFetcher extends UrlFetcher
{
    public function __construct(
        private readonly UrlFetcher $inner = new UrlFetcher(),
    ) {}

    public function fetch(string $url): string
    {
        $content = $this->inner->fetch($url);

        if (preg_match('/^Scoreboard: /m', $content) !== 1) {
            throw new \RuntimeException(sprintf('"%s" is not a mod_status report.', $url));
        }

        return $content;
    }
}
