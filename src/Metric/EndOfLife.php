<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Metric;

use Jmonitor\Utils\UrlFetcher;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tells the support status of a version from the release cycles published by endoflife.date. The
 * cycles of a product are kept in the cache pool; a product that cannot be read gives no badge
 * until its failure expires.
 */
final readonly class EndOfLife
{
    private const URL = 'https://endoflife.date/api/%s.json';
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const FETCHING = 60;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger = new NullLogger(),
        private int $lifetime = 86_400,
        private int $errorLifetime = 3_600,
        private UrlFetcher $fetcher = new UrlFetcher(),
    ) {}

    /**
     * @param string $product the endoflife.date product, such as "php" or "postgresql"
     */
    public function badge(string $product, ?string $version): ?Badge
    {
        $cycle = $version === null ? null : $this->cycle($product, $version);

        if ($cycle === null) {
            return null;
        }

        // The dates are the last day of support.
        $today = $this->clock->now()->format('Y-m-d');
        $ended = is_bool($cycle['eol']) ? $cycle['eol'] : $cycle['eol'] < $today;
        $supported = match (true) {
            $cycle['support'] === null => !$ended,
            is_bool($cycle['support']) => $cycle['support'],
            default => $cycle['support'] >= $today,
        };

        return match (true) {
            $ended => new Badge(BadgeStyle::Danger, 'End of life'),
            $supported => new Badge(BadgeStyle::Success, 'Active support'),
            default => new Badge(BadgeStyle::Warning, 'Security fixes only'),
        };
    }

    /**
     * "16.4 (Debian 16.4-1)" is looked up as cycle "16.4", then "16".
     *
     * @return ?array{eol: bool|string, support: bool|string|null}
     */
    private function cycle(string $product, string $version): ?array
    {
        if (preg_match('/^v?(\d+)(?:\.(\d+))?/', $version, $matches) !== 1) {
            return null;
        }

        $cycles = $this->cycles($product);
        $candidates = isset($matches[2]) ? [$matches[1] . '.' . $matches[2], $matches[1]] : [$matches[1]];

        foreach ($candidates as $candidate) {
            if (isset($cycles[$candidate])) {
                return $cycles[$candidate];
            }
        }

        return null;
    }

    /**
     * @return array<string, array{eol: bool|string, support: bool|string|null}>
     */
    private function cycles(string $product): array
    {
        $item = $this->cache->getItem('jmonitor_easyadmin.eol.' . $product);
        $cached = $item->isHit() ? $item->get() : null;

        if (is_array($cached) && is_array($cached['cycles'] ?? null) && self::areCycles($cached['cycles'])) {
            return $cached['cycles'];
        }

        // The requests opened during the fetch show no badge instead of fetching too.
        $item->set(['cycles' => []]);
        $item->expiresAfter(self::FETCHING);
        $this->cache->save($item);

        try {
            $cycles = self::parse($this->fetcher->fetchJson(sprintf(self::URL, $product)));
            $item->expiresAfter($this->lifetime);
        } catch (\Throwable $e) {
            $this->logger->notice('Cannot read the release cycles of "{product}" from endoflife.date: {reason}', ['product' => $product, 'reason' => $e->getMessage(), 'exception' => $e]);
            $cycles = [];
            $item->expiresAfter($this->errorLifetime);
        }

        $item->set(['cycles' => $cycles]);
        $this->cache->save($item);

        return $cycles;
    }

    /**
     * @param array<mixed> $response
     *
     * @return array<string, array{eol: bool|string, support: bool|string|null}>
     */
    private static function parse(array $response): array
    {
        $cycles = [];

        foreach ($response as $entry) {
            if (!is_array($entry) || !is_scalar($entry['cycle'] ?? null) || !self::isDate($entry['eol'] ?? null)) {
                continue;
            }

            $support = $entry['support'] ?? null;
            $cycles[(string) $entry['cycle']] = ['eol' => $entry['eol'], 'support' => self::isDate($support) ? $support : null];
        }

        if ($cycles === []) {
            throw new \RuntimeException('no release cycle in the response.');
        }

        return $cycles;
    }

    /**
     * @param array<mixed> $cycles
     *
     * @phpstan-assert-if-true array<string, array{eol: bool|string, support: bool|string|null}> $cycles
     */
    private static function areCycles(array $cycles): bool
    {
        foreach ($cycles as $cycle) {
            if (!is_array($cycle) || !self::isDate($cycle['eol'] ?? null) || !array_key_exists('support', $cycle) || ($cycle['support'] !== null && !self::isDate($cycle['support']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true bool|string $value
     */
    private static function isDate(mixed $value): bool
    {
        return is_bool($value) || (is_string($value) && preg_match(self::DATE, $value) === 1);
    }
}
