<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\UrlFetcher;

/**
 * Refuses a page that is not the machine-readable report of mod_status, such as the application
 * answering in its place: ApacheCollector needs its Scoreboard line. Keeps the last report for
 * the values ApacheCollector leaves out or rounds.
 */
final class ModStatusFetcher extends UrlFetcher
{
    private ?string $report = null;

    public function __construct(
        private readonly UrlFetcher $inner,
    ) {}

    public function fetch(string $url): string
    {
        $this->report = null;
        $content = $this->inner->fetch($url);

        if (preg_match('/^Scoreboard: /m', $content) !== 1) {
            throw new \RuntimeException(sprintf('"%s" is not a mod_status report.', $url));
        }

        return $this->report = $content;
    }

    public function value(string $key): ?float
    {
        if ($this->report === null || preg_match('/^' . preg_quote($key, '/') . ': ([\d.]+)\r?$/m', $this->report, $m) !== 1) {
            return null;
        }

        return is_numeric($m[1]) ? (float) $m[1] : null;
    }
}
