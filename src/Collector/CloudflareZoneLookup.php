<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Resolves the configured zone, a name or an id, into the ids every Cloudflare call needs,
 * kept in the cache pool: they never change.
 */
final readonly class CloudflareZoneLookup
{
    public function __construct(
        private string $zone,
        private CacheItemPoolInterface $cache,
        private int $lifetime,
    ) {}

    /**
     * @throws \RuntimeException when the token or the zone is refused
     * @throws UnreachableServerException when Cloudflare gave no answer
     */
    public function find(CloudflareApi $api): CloudflareZone
    {
        $item = $this->cache->getItem($this->key());
        $cached = $item->get();

        if (is_array($cached) && is_string($cached['id'] ?? null) && is_string($cached['name'] ?? null) && is_string($cached['account_id'] ?? null)) {
            return new CloudflareZone($cached['id'], $cached['name'], $cached['account_id']);
        }

        try {
            $details = $this->fetch($api);
        } catch (CloudflareException $e) {
            if ($e->getReason() === CloudflareException::FORBIDDEN) {
                throw new \RuntimeException(sprintf('Cloudflare rejected the API token while looking up the zone "%s": check that the token is valid and has the Zone › Zone › Read permission.', $this->zone), 0, $e);
            }

            if ($e->getStatusCode() === 404) {
                throw new \RuntimeException($this->notFound(), 0, $e);
            }

            throw UnreachableServerException::withRootCause(sprintf('Cannot look up the Cloudflare zone "%s".', $this->zone), $e);
        }

        if (!isset($details['id'], $details['name'])) {
            throw new \RuntimeException($this->notFound());
        }

        $zone = new CloudflareZone((string) $details['id'], (string) $details['name'], (string) ($details['account']['id'] ?? ''));

        $this->cache->save($item
            ->set(['id' => $zone->id, 'name' => $zone->name, 'account_id' => $zone->accountId])
            ->expiresAfter($this->lifetime));

        return $zone;
    }

    public function forget(): void
    {
        $this->cache->deleteItem($this->key());
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws CloudflareException
     */
    private function fetch(CloudflareApi $api): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $this->zone) === 1) {
            $result = $api->get('/zones/' . $this->zone)['result'] ?? null;

            return is_array($result) ? $result : null;
        }

        foreach ((array) ($api->get('/zones', ['name' => $this->zone])['result'] ?? []) as $row) {
            if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $this->zone) === 0) {
                return $row;
            }
        }

        return null;
    }

    private function notFound(): string
    {
        return sprintf('Cloudflare zone "%s" not found for this API token.', $this->zone);
    }

    private function key(): string
    {
        return 'jmonitor_easyadmin.cloudflare.zone.' . hash('xxh128', strtolower($this->zone));
    }
}
