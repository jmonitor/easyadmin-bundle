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
