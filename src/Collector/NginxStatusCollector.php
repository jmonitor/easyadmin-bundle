<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Exceptions\JmonitorException;

/**
 * Turns an unreachable status page, or a page that is not stub_status, into an error that says how
 * to expose it, with its root cause chained for SnapshotProvider::describe(). The failure is an
 * UnreachableServerException, kept longer than a poll: the default endpoint is the web server
 * itself, which would boot the application again at each poll to answer that the page does not exist.
 *
 * When "nginx -V" gives no version, the payload takes the one Nginx passed to PHP-FPM. A Refresh
 * also drops the cached outputs of the commands.
 */
final readonly class NginxStatusCollector implements CollectorInterface, RefreshableCollectorInterface
{
    public function __construct(
        private NginxCollector $inner,
        private string $endpoint,
        private CachedShellExecutor $shell,
        private ?string $fallbackVersion = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            $payload = $this->inner->collect();

            if (($payload['status']['active'] ?? null) === null) {
                throw new \RuntimeException(sprintf('"%s" is not a stub_status page.', $this->endpoint));
            }
        } catch (JmonitorException|\RuntimeException $e) {
            $cause = $e;

            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            throw new UnreachableServerException(sprintf('Cannot read the Nginx status at %s. Enable stub_status in a location reachable from PHP and check "components.nginx.endpoint".', $this->endpoint), 0, $cause);
        }

        $payload['version'] ??= $this->fallbackVersion;

        return $payload;
    }

    public function refresh(): void
    {
        $this->shell->forget();
    }

    public function getVersion(): int
    {
        return $this->inner->getVersion();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }
}
