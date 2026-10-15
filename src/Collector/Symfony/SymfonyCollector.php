<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector\Symfony;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The kernel's description, plus the payload of each component collector that did not fail.
 *
 * @internal
 */
final class SymfonyCollector implements CollectorInterface, LoggerAwareInterface, BootableCollectorInterface
{
    use LoggerAwareTrait;

    /** @var array<string, true> */
    private array $disabledComponents = [];

    /**
     * @param array<string, ComponentCollectorInterface> $componentCollectors
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly array $componentCollectors,
    ) {}

    public function boot(): void
    {
        foreach ($this->componentCollectors as $name => $collector) {
            try {
                $collector->boot();
            } catch (\Throwable $e) {
                $cause = $e instanceof BootFailedException ? ($e->getPrevious() ?? $e) : $e;

                $level = $cause instanceof CollectorException ? LogLevel::WARNING : LogLevel::ERROR;

                $this->logger?->log($level, 'Symfony component "{component}" failed to boot.', [
                    'component' => $name,
                    'message' => $cause->getMessage(),
                    'exception' => $cause,
                ]);

                $this->disabledComponents[$name] = true;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $components = [];

        foreach ($this->componentCollectors as $name => $collector) {
            if (isset($this->disabledComponents[$name])) {
                continue;
            }

            try {
                $components[$name] = $collector->collect();
            } catch (CollectorException $e) {
                $this->logger?->warning($e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $e) {
                $this->logger?->error('Symfony component "{component}" failed to collect.', [
                    'component' => $name,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return [
            'env' => $this->kernel->getEnvironment(),
            'debug' => $this->kernel->isDebug(),
            'version' => $this->symfonyVersion(),
            'bundles' => array_keys($this->kernel->getBundles()),
            'project_dir' => $this->kernel->getProjectDir(),
            'cache_dir' => $this->kernel->getCacheDir(),
            'log_dir' => $this->kernel->getLogDir(),
            'build_dir' => $this->kernel->getBuildDir(),
            'share_dir' => method_exists($this->kernel, 'getShareDir') ? $this->kernel->getShareDir() : null,
            'charset' => $this->kernel->getCharset(),
            'components' => array_filter($components),
        ];
    }

    public function getName(): string
    {
        return 'symfony';
    }

    public function getVersion(): int
    {
        return 1;
    }

    private function symfonyVersion(): ?string
    {
        $constant = $this->kernel::class . '::VERSION';

        return defined($constant) && is_string(constant($constant)) ? constant($constant) : null;
    }
}
