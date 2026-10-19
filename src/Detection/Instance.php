<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;

/**
 * One monitored thing inside a component: a Doctrine connection of the MySQL tab, for instance.
 * Components that monitor a single thing have one implicit instance, without id.
 */
final readonly class Instance
{
    public const UNAVAILABLE = 'This component is not available.';

    private function __construct(
        public Component $component,
        public ?string $id,
        public ?string $detail,
        public ?CollectorSet $collectors,
        public ?string $error,
        public ?Server $server = null,
    ) {}

    public static function found(Component $component, CollectorSet $collectors, ?string $id = null, ?string $detail = null, ?Server $server = null): self
    {
        return new self($component, $id, $detail, $collectors, null, $server);
    }

    public static function unavailable(Component $component, string $error, ?string $id = null, ?string $detail = null): self
    {
        return new self($component, $id, $detail, null, $error);
    }

    /**
     * @phpstan-assert-if-true CollectorSet $this->collectors
     */
    public function isFound(): bool
    {
        return $this->collectors !== null;
    }

    /**
     * Namespace of the cache entries of this instance, so that two instances sharing collector names never
     * collide. The id is URL-encoded: a cache key must not contain the characters PSR-6 reserves.
     */
    public function keyPrefix(): string
    {
        return $this->id === null ? '' : $this->component->value . '.' . rawurlencode($this->id) . '.';
    }

    /**
     * Namespace of the entries shared by the instances of one server. The unencoded "%server" segment
     * cannot appear in keyPrefix(), where rawurlencode() turns any "%" into "%25".
     */
    public function serverKeyPrefix(): ?string
    {
        return $this->server === null ? null : $this->component->value . '.%server.' . hash('xxh128', $this->server->key) . '.';
    }
}
