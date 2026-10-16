<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\EasyAdminBundle\Component;

final readonly class CollectorSet
{
    /**
     * @param list<CollectorSpec> $specs
     */
    public function __construct(
        public Component $component,
        public array $specs,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn(CollectorSpec $spec): string => $spec->name, $this->specs);
    }

    public function get(string $name): ?CollectorSpec
    {
        foreach ($this->specs as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        return null;
    }
}
