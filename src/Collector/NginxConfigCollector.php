<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;

/**
 * The version, modules, configuration and CPU count, read from the commands of NginxCollector
 * without the status page, which NginxStatusCollector reads. Without a version from "nginx -V", the
 * payload takes the one Nginx passed to PHP-FPM.
 */
final readonly class NginxConfigCollector implements CollectorInterface
{
    private NginxCollector $inner;

    public function __construct(
        ShellExecutor $shell,
        private ?string $fallbackVersion = null,
    ) {
        $noStatus = new class extends UrlFetcher {
            public function fetch(string $url): string
            {
                return '';
            }
        };
        $this->inner = new NginxCollector('', $shell, $noStatus);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $payload = $this->inner->collect();
        unset($payload['status']);
        $payload['version'] ??= $this->fallbackVersion;

        return $payload;
    }

    public function getVersion(): int
    {
        return $this->inner->getVersion();
    }

    public function getName(): string
    {
        return 'nginx.config';
    }
}
