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

        $hostCounts = [];
        foreach ($groups as $group) {
            $server = $group[0]->server;
            if ($server !== null) {
                $hostCounts[$server->host] = ($hostCounts[$server->host] ?? 0) + 1;
            }
        }

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

            $label = $server->host;

            if ($hostCounts[$server->host] > 1) {
                $label = $server->port === null ? ($server->socket ?? $label) : $server->host . ':' . $server->port;
            }

            $pills[] = new NavigationItem($label, null, $first, $active, null);
        }

        $count = count($databases);

        return new self(
            count($groups) > 1 ? $pills : [],
            $databases,
            $count <= 1 ? null : ($count === 2 ? self::PILLS : self::SELECT),
        );
    }
}
