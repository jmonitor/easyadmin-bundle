<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;

/**
 * The stub_status page only: NginxConfigCollector runs the commands, at their own pace. A page that
 * cannot be read, or that is not stub_status, is an error that says how to expose it, and an
 * UnreachableServerException: the default endpoint is the web server itself, which would boot the
 * application again to answer that the page does not exist.
 */
final readonly class NginxStatusCollector implements CollectorInterface
{
    private NginxCollector $inner;

    public function __construct(
        private string $endpoint,
        ?UrlFetcher $urlFetcher = null,
    ) {
        $noCommand = new class extends ShellExecutor {
            public function execute(string $command): ?string
            {
                return null;
            }
        };
        $this->inner = new NginxCollector($endpoint, $noCommand, $urlFetcher);
    }

    /**
     * @return array{status: array<string, ?int>}
     */
    public function collect(): array
    {
        try {
            $status = $this->inner->collect()['status'];

            if (($status['active'] ?? null) === null) {
                throw new \RuntimeException(sprintf('"%s" is not a stub_status page.', $this->endpoint));
            }
        } catch (JmonitorException|\RuntimeException $e) {
            throw UnreachableServerException::withRootCause(sprintf('Cannot read the Nginx status at %s. Enable stub_status in a location reachable from PHP and check "components.nginx.endpoint".', $this->endpoint), $e);
        }

        return ['status' => $status];
    }

    public function getVersion(): int
    {
        return $this->inner->getVersion();
    }

    public function getName(): string
    {
        return 'nginx.status';
    }
}
