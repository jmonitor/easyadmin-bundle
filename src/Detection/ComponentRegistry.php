<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Applies the "enabled" setting of each component to its detector:
 * false never detects, auto keeps only what is found, true keeps the component even when
 * detection failed so the page can explain why.
 */
final class ComponentRegistry implements ResetInterface
{
    /** @var array<string, Detection>|null keyed by component value, in Component::cases() order */
    private ?array $detections = null;

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
        return array_map(static fn(string $value): Component => Component::from($value), array_keys($this->detections()));
    }

    public function isAvailable(Component $component): bool
    {
        return isset($this->detections()[$component->value]);
    }

    public function collectorsFor(Component $component): ?CollectorSet
    {
        return ($this->detections()[$component->value] ?? null)?->collectors;
    }

    public function errorFor(Component $component): ?string
    {
        return ($this->detections()[$component->value] ?? null)?->error;
    }

    public function reset(): void
    {
        $this->detections = null;
    }

    /**
     * @return array<string, Detection>
     */
    private function detections(): array
    {
        if ($this->detections !== null) {
            return $this->detections;
        }

        $detectors = [];
        foreach ($this->detectors as $detector) {
            $detectors[$detector->component()->value] = $detector;
        }

        $detections = [];
        foreach (Component::cases() as $component) {
            $enabled = $this->components[$component->value]['enabled'] ?? 'auto';
            $detector = $detectors[$component->value] ?? null;

            if ($enabled === false || $detector === null) {
                continue;
            }

            $detection = $detector->detect();

            if ($enabled === 'auto' && !$detection->isFound()) {
                continue;
            }

            $detections[$component->value] = $detection;
        }

        return $this->detections = $detections;
    }
}
