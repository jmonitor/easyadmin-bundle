<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Release;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The latest release of a repository, kept in the cache pool; a release that cannot be read gives
 * nothing until its failure expires.
 */
final readonly class Releases
{
    private const FETCHING = 60;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger = new NullLogger(),
        private ReleaseSourceInterface $source = new GithubReleaseSource(),
        private int $lifetime = 86_400,
        private int $errorLifetime = 3_600,
    ) {}

    /**
     * @param string $repository "owner/name"
     */
    public function latest(string $repository): ?LatestRelease
    {
        $item = $this->cache->getItem('jmonitor_easyadmin.release.' . str_replace('/', '.', $repository));
        $cached = $item->isHit() ? $item->get() : null;

        if (is_array($cached) && array_key_exists('release', $cached)) {
            $release = $cached['release'];

            if ($release === null) {
                return null;
            }

            if (is_array($release) && is_string($release['version'] ?? null) && is_string($release['url'] ?? null)) {
                return new LatestRelease($release['version'], $release['url']);
            }
        }

        // The requests opened during the fetch get nothing instead of fetching too.
        $item->set(['release' => null]);
        $item->expiresAfter(self::FETCHING);
        $this->cache->save($item);

        try {
            $release = $this->source->latest($repository);
            $item->set(['release' => ['version' => $release->version, 'url' => $release->url]]);
            $item->expiresAfter($this->lifetime);
        } catch (\Throwable $e) {
            $this->logger->notice('Cannot read the latest release of "{repository}" from GitHub: {reason}', ['repository' => $repository, 'reason' => $e->getMessage(), 'exception' => $e]);
            $release = null;
            $item->expiresAfter($this->errorLifetime);
        }

        $this->cache->save($item);

        return $release;
    }
}
