<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class SymfonyView
{
    public bool $available;
    public ?string $version;
    public ?string $environment;
    public ?Badge $debug;

    public bool $recommendationsAvailable;
    public Badge $opcache;
    public Badge $apcu;
    public ?int $realpathCacheSize;
    public ?BadgeStyle $realpathCacheSizeStyle;
    public ?Badge $realpathCacheTtl;
    /** @var list<string> */
    public array $bundles;

    public bool $flexAvailable;
    public bool $flexCheckFailed;
    public ?Badge $flex;
    public bool $recipesUpToDate;
    /** @var list<string> */
    public array $outdatedRecipes;

    public bool $messengerAvailable;
    /** @var array<string, Badge|int|null> transport name => queued messages */
    public array $messengerTransports;
    /** @var list<string> */
    public array $messengerUncountable;

    public bool $schedulerAvailable;
    /** @var list<array{command: ?string, description: ?string, trigger: ?string, jitter: ?string, next_run: ?int}> */
    public array $schedulerTasks;
    /** @var ?array{command: ?string, description: ?string, trigger: ?string, jitter: ?string, next_run: ?int} */
    public ?array $nextTask;

    public function __construct(ComponentSnapshot $symfony, ComponentSnapshot $php)
    {
        $data = $symfony->data('symfony');
        $components = is_array($data['components'] ?? null) ? $data['components'] : [];

        $this->available = $symfony->has('symfony');
        $this->version = Values::string($data['version'] ?? null);
        $environment = Values::string($data['env'] ?? null);
        $this->environment = $environment === null ? null : strtoupper($environment);
        $this->debug = match (Values::bool($data['debug'] ?? null)) {
            true => new Badge(BadgeStyle::Warning, 'Debug: enabled'),
            false => new Badge(BadgeStyle::Success, 'Debug: disabled'),
            null => null,
        };

        $phpData = $php->data('php');
        $this->recommendationsAvailable = $php->has('php');
        $this->opcache = PhpView::opcacheRuns(is_array($phpData['opcache'] ?? null) ? $phpData['opcache'] : [])
            ? new Badge(BadgeStyle::Success, 'Enabled')
            : new Badge(BadgeStyle::Danger, 'Disabled');
        $this->apcu = Values::bool($phpData['apcu']['config']['apc.enabled'] ?? null) === true
            ? new Badge(BadgeStyle::Success, 'Enabled')
            : new Badge(BadgeStyle::Warning, 'Disabled');
        $realpathCacheSize = Values::iniBytes($phpData['realpath_cache_size'] ?? null);
        $this->realpathCacheSize = is_int($realpathCacheSize) ? $realpathCacheSize : null;
        $this->realpathCacheSizeStyle = $this->realpathCacheSize === null ? null : ($this->realpathCacheSize >= 4_096_000 ? BadgeStyle::Success : BadgeStyle::Warning);
        $realpathCacheTtl = Values::int($phpData['realpath_cache_ttl'] ?? null);
        $this->realpathCacheTtl = $realpathCacheTtl === null ? null : new Badge($realpathCacheTtl >= 600 ? BadgeStyle::Success : BadgeStyle::Warning, (string) $realpathCacheTtl);
        $this->bundles = is_array($data['bundles'] ?? null) ? array_values(array_filter($data['bundles'], 'is_string')) : [];

        $flex = is_array($components['flex_recipes'] ?? null) ? $components['flex_recipes'] : null;
        $this->flexAvailable = $flex !== null && $flex !== [];
        $this->recipesUpToDate = Values::bool($flex['up_to_date'] ?? null) === true;
        $this->outdatedRecipes = is_array($flex['outdated_recipes'] ?? null) ? array_values(array_filter($flex['outdated_recipes'], 'is_string')) : [];
        $this->flexCheckFailed = $this->flexAvailable && !$this->recipesUpToDate && $this->outdatedRecipes === [];
        $this->flex = match (true) {
            !$this->flexAvailable, $this->flexCheckFailed => null,
            $this->outdatedRecipes !== [] => new Badge(BadgeStyle::Warning, 'Outdated'),
            default => new Badge(BadgeStyle::Success, 'Up to date'),
        };

        $messenger = is_array($components['messenger'] ?? null) ? $components['messenger'] : null;
        $this->messengerAvailable = $messenger !== null;
        $transports = [];
        foreach (is_array($messenger['transports'] ?? null) ? $messenger['transports'] : [] as $name => $transport) {
            $count = Values::int(is_array($transport) ? ($transport['count'] ?? null) : $transport);
            $transports[(string) $name] = match (true) {
                $count === null || $count <= 0 => $count,
                $name === 'failed' => new Badge(BadgeStyle::Danger, (string) $count),
                default => new Badge(BadgeStyle::Info, (string) $count),
            };
        }
        $this->messengerTransports = $transports;
        $this->messengerUncountable = is_array($messenger['uncountable_transports'] ?? null) ? array_values(array_filter($messenger['uncountable_transports'], 'is_string')) : [];

        $scheduler = is_array($components['scheduler'] ?? null) ? $components['scheduler'] : null;
        $this->schedulerAvailable = $scheduler !== null;
        $tasks = [];
        foreach ($scheduler ?? [] as $task) {
            if (!is_array($task)) {
                continue;
            }

            [$trigger, $jitter] = self::trigger(Values::string($task['trigger'] ?? null));
            $tasks[] = [
                'command' => Values::string($task['command'] ?? null),
                'description' => Values::string($task['description'] ?? null),
                'trigger' => $trigger,
                'jitter' => $jitter,
                'next_run' => Values::int($task['next_run'] ?? null),
            ];
        }
        usort($tasks, static fn(array $a, array $b): int => ($a['next_run'] ?? PHP_INT_MAX) <=> ($b['next_run'] ?? PHP_INT_MAX));
        $this->schedulerTasks = $tasks;
        $this->nextTask = $tasks[0] ?? null;
    }

    /**
     * Splits "every 3 hour with 0-10 second jitter" into the trigger and its jitter.
     *
     * @return array{?string, ?string}
     */
    private static function trigger(?string $trigger): array
    {
        if ($trigger !== null && preg_match('/^(.+?) with (.+?) jitter$/', $trigger, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$trigger, null];
    }
}
