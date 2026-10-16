<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Snapshot;

final readonly class CollectorResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $name,
        public array $data,
        public ?string $error,
        public int $collectedAt,
        public ?Delta $delta = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * @return array{data: array<string, mixed>, error: ?string, collected_at: int, delta: ?array{previous: array<string, mixed>, current: array<string, mixed>, elapsed: int}}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'error' => $this->error,
            'collected_at' => $this->collectedAt,
            'delta' => $this->delta?->toArray(),
        ];
    }

    /**
     * A cached payload written by another version of the bundle must count as a miss, not a crash.
     */
    public static function isValidArray(mixed $values): bool
    {
        if (!is_array($values) || !is_array($values['data'] ?? null) || !is_int($values['collected_at'] ?? null)) {
            return false;
        }

        if (!array_key_exists('error', $values) || ($values['error'] !== null && !is_string($values['error']))) {
            return false;
        }

        $delta = $values['delta'] ?? null;

        return $delta === null || (is_array($delta) && is_array($delta['previous'] ?? null) && is_array($delta['current'] ?? null) && is_int($delta['elapsed'] ?? null));
    }

    /**
     * @param array{data: array<string, mixed>, error: ?string, collected_at: int, delta: ?array{previous: array<string, mixed>, current: array<string, mixed>, elapsed: int}} $values
     */
    public static function fromArray(string $name, array $values): self
    {
        return new self(
            $name,
            $values['data'],
            $values['error'],
            $values['collected_at'],
            $values['delta'] === null ? null : Delta::fromArray($values['delta']),
        );
    }
}
