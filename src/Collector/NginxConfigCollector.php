<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;

/**
 * The version, modules, configuration and CPU count, read from "nginx -V", "nginx -T" and "nproc"
 * without the status page, which NginxStatusCollector reads. "nginx -V" prints on its error output.
 * Without a version from it, the payload takes the one Nginx passed to PHP-FPM.
 */
final readonly class NginxConfigCollector implements CollectorInterface
{
    private NginxCollector $inner;

    public function __construct(
        ShellExecutor $shell,
        private ?string $fallbackVersion = null,
    ) {
        $withErrorOutput = new class ($shell) extends ShellExecutor {
            public function __construct(
                private readonly ShellExecutor $inner,
            ) {}

            public function execute(string $command): ?string
            {
                return $this->inner->execute($command === 'nginx -V' ? 'nginx -V 2>&1' : $command);
            }
        };
        $noStatus = new class extends UrlFetcher {
            public function fetch(string $url): string
            {
                return '';
            }
        };
        $this->inner = new NginxCollector('', $withErrorOutput, $noStatus);
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
