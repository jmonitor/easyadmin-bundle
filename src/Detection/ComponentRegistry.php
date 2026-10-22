<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Component;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Applies the "enabled" setting of each component to its detector:
 * false never detects, auto keeps only what is found, true keeps the component even when
 * detection failed so the page can explain why. A component is detected when first asked for:
 * the polling of a tab leaves the detectors of the other components alone.
 */
final class ComponentRegistry implements ResetInterface
{
    /** @var ?array<string, ComponentDetectorInterface> keyed by component value */
    private ?array $byComponent = null;

    /** @var array<string, ComponentStatus> keyed by component value */
    private array $statuses = [];

    /**
     * @param iterable<string, ComponentDetectorInterface>      $detectors  keyed by component value
     * @param array<string, array{enabled: 'auto'|bool}> $components the "components" configuration
     */
    public function __construct(
        private readonly iterable $detectors,
        private readonly array $components,
    ) {}

    /**
     * @return list<Component>
     */
    public function available(): array
    {
        return array_values(array_filter(Component::cases(), $this->isAvailable(...)));
    }

    public function isAvailable(Component $component): bool
    {
        return $this->instancesFor($component) !== [];
    }

    /**
     * @return list<Instance> empty when the component is not available
     */
    public function instancesFor(Component $component): array
    {
        return $this->status($component)->instances;
    }

    public function status(Component $component): ComponentStatus
    {
        return $this->statuses[$component->value] ??= $this->detect($component);
    }

    /**
     * @param ?string $id null for the first instance
     */
    public function instance(Component $component, ?string $id): ?Instance
    {
        $instances = $this->instancesFor($component);

        if ($id === null) {
            return $instances[0] ?? null;
        }

        foreach ($instances as $instance) {
            if ($instance->id === $id) {
                return $instance;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->statuses = [];
    }

    private function detect(Component $component): ComponentStatus
    {
        $enabled = $this->components[$component->value]['enabled'] ?? 'auto';
        $detector = $this->detectors()[$component->value] ?? null;

        if ($enabled === false || $detector === null) {
            return new ComponentStatus($component, Availability::Disabled, []);
        }

        try {
            $detection = $detector->detect();
        } catch (\Throwable $e) {
            $detection = Detection::unavailable($e->getMessage());
        }

        if ($detection->isFound()) {
            return new ComponentStatus($component, Availability::Available, $detection->instances);
        }

        $reason = $detection->error ?? Instance::UNAVAILABLE;

        return $enabled === true
            ? new ComponentStatus($component, Availability::Available, [Instance::unavailable($component, $reason)])
            : new ComponentStatus($component, Availability::NotDetected, [], $reason);
    }

    /**
     * @return array<string, ComponentDetectorInterface>
     */
    private function detectors(): array
    {
        if ($this->byComponent === null) {
            $this->byComponent = [];

            foreach ($this->detectors as $detector) {
                $this->byComponent[$detector->component()->value] = $detector;
            }
        }

        return $this->byComponent;
    }
}
