<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Component;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Applies the "enabled" setting of each component to its detector:
 * false never detects, auto keeps only what is found, true keeps the component even when
 * detection failed so the page can explain why.
 */
final class ComponentRegistry implements ResetInterface
{
    /** @var array<string, non-empty-list<Instance>>|null keyed by component value, in Component::cases() order */
    private ?array $instances = null;

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
        return array_map(static fn(string $value): Component => Component::from($value), array_keys($this->instances()));
    }

    public function isAvailable(Component $component): bool
    {
        return isset($this->instances()[$component->value]);
    }

    /**
     * @return list<Instance> empty when the component is not available
     */
    public function instancesFor(Component $component): array
    {
        return $this->instances()[$component->value] ?? [];
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
        $this->instances = null;
    }

    /**
     * @return array<string, non-empty-list<Instance>>
     */
    private function instances(): array
    {
        if ($this->instances !== null) {
            return $this->instances;
        }

        $detectors = [];
        foreach ($this->detectors as $detector) {
            $detectors[$detector->component()->value] = $detector;
        }

        $instances = [];
        foreach (Component::cases() as $component) {
            $enabled = $this->components[$component->value]['enabled'] ?? 'auto';
            $detector = $detectors[$component->value] ?? null;

            if ($enabled === false || $detector === null) {
                continue;
            }

            try {
                $detection = $detector->detect();
            } catch (\Throwable $e) {
                $detection = Detection::unavailable($e->getMessage());
            }

            if ($detection->isFound()) {
                $instances[$component->value] = $detection->instances;
            } elseif ($enabled === true) {
                $instances[$component->value] = [Instance::unavailable($component, $detection->error ?? Instance::UNAVAILABLE)];
            }
        }

        return $this->instances = $instances;
    }
}
