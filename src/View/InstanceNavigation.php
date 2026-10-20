<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Detection\Instance;

/**
 * What lets the user move between the instances of a component: one pill per server when the
 * instances know theirs (the databases of a server are then picked apart), one per instance otherwise.
 */
final readonly class InstanceNavigation
{
    public const PILLS = 'pills';
    public const SELECT = 'select';

    /**
     * @param list<NavigationItem> $pills
     * @param list<Instance>       $databases
     */
    public function __construct(
        public array $pills,
        public array $databases,
        public ?string $databaseSelector,
    ) {}

    /**
     * @param list<Instance> $instances
     */
    public static function of(array $instances, ?Instance $current): self
    {
        $groups = [];
        foreach ($instances as $index => $instance) {
            $groups[$instance->server === null ? '#' . $index : 's:' . $instance->server->key][] = $instance;
        }
        $groups = array_values($groups);

        $pills = [];
        $databases = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $active = $current !== null && in_array($current, $group, true);

            if ($active && $first->server !== null) {
                $databases = $group;
            }

            $server = $first->server;
            if ($server === null) {
                $pills[] = new NavigationItem($first->id ?? '', $first->detail, $first, $active, $first->error);

                continue;
            }

            $target = $active ? $current : $first;
            $label = ($target->id ?? $server->address()) . (count($group) > 1 ? ' +' . (count($group) - 1) : '');

            $pills[] = new NavigationItem($label, null, $target, $active, null, $server->address());
        }

        $count = count($databases);

        return new self(
            count($groups) > 1 ? $pills : [],
            $databases,
            $count <= 1 ? null : ($count === 2 ? self::PILLS : self::SELECT),
        );
    }
}
