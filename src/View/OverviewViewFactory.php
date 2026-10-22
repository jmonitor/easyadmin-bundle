<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Composer\InstalledVersions;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Availability;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\ComponentStatus;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Detection\SkippedConnectionReporter;
use Jmonitor\EasyAdminBundle\Release\Releases;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Builds the overview from the detection and what PHP knows of itself: it never collects.
 */
final readonly class OverviewViewFactory
{
    private const PACKAGES = ['jmonitor/easyadmin-bundle', 'jmonitor/collector'];
    private const INSTANCES_SHOWN = 5;

    /** @var \Closure(string): ?string */
    private \Closure $installedVersion;

    /**
     * @param ?\Closure(string): ?string $installedVersion the installed version of a package, from Composer by default
     */
    public function __construct(
        private ComponentRegistry $registry,
        private SkippedConnectionReporter $skipped,
        private Releases $releases,
        private RequestStack $requestStack,
        private int $pollingInterval,
        private int $staticTtl,
        private string $cachePool,
        private ?string $permission,
        ?\Closure $installedVersion = null,
    ) {
        $this->installedVersion = $installedVersion ?? static fn(string $package): ?string => InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
    }

    public function create(): OverviewView
    {
        $components = array_map($this->component(...), array_map($this->registry->status(...), Component::cases()));
        $versions = array_map(fn(string $package): PackageVersion => PackageVersion::of($package, ($this->installedVersion)($package), $this->releases->latest($package)), self::PACKAGES);

        return new OverviewView($components, $versions, $this->pollingInterval, $this->staticTtl, $this->cachePool, $this->permission, $this->skipped->skipped());
    }

    private function component(ComponentStatus $status): OverviewComponent
    {
        $component = $status->component;

        if ($status->availability === Availability::NotDetected) {
            return new OverviewComponent($component, $status->availability, false, $status->reason);
        }

        if ($status->availability === Availability::Disabled) {
            return new OverviewComponent($component, $status->availability, false, 'Disabled in the configuration.');
        }

        $failing = array_filter($status->instances, static fn(Instance $instance): bool => !$instance->isFound()) === $status->instances;
        $named = array_values(array_filter($status->instances, static fn(Instance $instance): bool => $instance->id !== null));

        if ($named === []) {
            $implicit = $status->instances[0] ?? null;

            return new OverviewComponent($component, $status->availability, $failing, $implicit->error ?? $this->detail($component, $implicit));
        }

        usort($named, static fn(Instance $a, Instance $b): int => ($b->error !== null) <=> ($a->error !== null));
        $shown = array_map(static fn(Instance $instance): OverviewInstance => new OverviewInstance((string) $instance->id, $instance->error ?? $instance->detail, $instance->error !== null), array_slice($named, 0, self::INSTANCES_SHOWN));

        return new OverviewComponent($component, $status->availability, $failing, null, $shown, count($named) - count($shown));
    }

    private function detail(Component $component, ?Instance $instance): ?string
    {
        return match ($component) {
            Component::System => function_exists('php_uname') ? php_uname('s') . ' ' . php_uname('r') : PHP_OS_FAMILY,
            Component::Php => PHP_VERSION . ' · ' . PHP_SAPI,
            Component::Apache, Component::Nginx => $this->serverSoftware(),
            Component::Symfony => Kernel::VERSION,
            Component::FrankenPhp => null,
            Component::Mysql, Component::Postgresql, Component::Redis => $instance?->detail,
        };
    }

    private function serverSoftware(): ?string
    {
        $software = $this->requestStack->getMainRequest()?->server->get('SERVER_SOFTWARE');

        return is_string($software) && $software !== '' ? $software : null;
    }
}
