<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

use Jmonitor\EasyAdminBundle\Component;

final readonly class ComponentSnapshot
{
    /**
     * @param array<string, CollectorResult> $results keyed by collector name
     * @param ?string                        $error   why the whole component is unavailable, if it is
     */
    public function __construct(
        public Component $component,
        public array $results,
        public ?string $error = null,
    ) {}

    public function result(string $name): ?CollectorResult
    {
        return $this->results[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $name): array
    {
        $result = $this->result($name);

        return $result === null ? [] : $result->data;
    }

    public function has(string $name): bool
    {
        return $this->result($name)?->ok() ?? false;
    }

    public function delta(string $name): ?Delta
    {
        return $this->result($name)?->delta;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->results as $result) {
            if ($result->error !== null) {
                $errors[] = $result->name . ': ' . $result->error;
            }
        }

        return $errors;
    }
}
