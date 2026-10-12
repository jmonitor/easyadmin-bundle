<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class SymfonyView
{
    public bool $available;
    public ?string $version;
    public ?string $environment;
    public ?bool $debug;
    public bool $debugInProduction;
    public ?string $charset;
    public ?string $projectDir;
    public ?string $cacheDir;
    public ?string $logDir;
    /** @var list<string> */
    public array $bundles;
    /** @var list<Recommendation> */
    public array $recommendations;

    public bool $messengerAvailable;
    /** @var array<string, ?int> transport name => queued messages */
    public array $messengerTransports;
    /** @var list<string> */
    public array $messengerUncountable;

    public bool $schedulerAvailable;
    /** @var list<array{trigger: ?string, command: ?string, next_run: ?int, description: ?string}> */
    public array $schedulerTasks;
    /** @var ?array{trigger: ?string, command: ?string, next_run: ?int, description: ?string} */
    public ?array $nextTask;

    public bool $flexAvailable;
    public ?bool $recipesUpToDate;
    /** @var list<string> */
    public array $outdatedRecipes;

    public function __construct(ComponentSnapshot $symfony, ComponentSnapshot $php)
    {
        $data = $symfony->data('symfony');
        $components = is_array($data['components'] ?? null) ? $data['components'] : [];

        $this->available = $symfony->has('symfony');
        $this->version = Values::string($data['version'] ?? null);
        $this->environment = Values::string($data['env'] ?? null);
        $this->debug = Values::bool($data['debug'] ?? null);
        $this->debugInProduction = $this->environment === 'prod' && $this->debug === true;
        $this->charset = Values::string($data['charset'] ?? null);
        $this->projectDir = Values::string($data['project_dir'] ?? null);
        $this->cacheDir = Values::string($data['cache_dir'] ?? null);
        $this->logDir = Values::string($data['log_dir'] ?? null);

        $bundles = is_array($data['bundles'] ?? null) ? array_values(array_filter($data['bundles'], 'is_string')) : [];
        sort($bundles, SORT_FLAG_CASE | SORT_STRING);
        $this->bundles = $bundles;

        $messenger = is_array($components['messenger'] ?? null) ? $components['messenger'] : null;
        $this->messengerAvailable = $messenger !== null;
        $transports = [];
        foreach (is_array($messenger['transports'] ?? null) ? $messenger['transports'] : [] as $name => $transport) {
            $transports[(string) $name] = Values::int(is_array($transport) ? ($transport['count'] ?? null) : $transport);
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

            $tasks[] = [
                'trigger' => Values::string($task['trigger'] ?? null),
                'command' => Values::string($task['command'] ?? null),
                'next_run' => Values::int($task['next_run'] ?? null),
                'description' => Values::string($task['description'] ?? null),
            ];
        }
        usort($tasks, static fn(array $a, array $b): int => ($a['next_run'] ?? PHP_INT_MAX) <=> ($b['next_run'] ?? PHP_INT_MAX));
        $this->schedulerTasks = $tasks;
        $this->nextTask = $tasks[0] ?? null;

        $flex = is_array($components['flex_recipes'] ?? null) ? $components['flex_recipes'] : null;
        $this->flexAvailable = $flex !== null;
        $this->recipesUpToDate = $flex === null ? null : Values::bool($flex['up_to_date'] ?? null);
        $this->outdatedRecipes = is_array($flex['outdated_recipes'] ?? null) ? array_values(array_filter($flex['outdated_recipes'], 'is_string')) : [];

        $this->recommendations = $this->recommendations($php->data('php'));
    }

    /**
     * @param array<string, mixed> $php
     *
     * @return list<Recommendation>
     */
    private function recommendations(array $php): array
    {
        $opcache = is_array($php['opcache'] ?? null) ? $php['opcache'] : [];
        $apcu = is_array($php['apcu'] ?? null) ? $php['apcu'] : [];
        $realpathSize = Values::iniBytes($php['realpath_cache_size'] ?? null);
        $realpathTtl = Values::int($php['realpath_cache_ttl'] ?? null);

        return [
            new Recommendation('OPcache enabled', $opcache === [] ? null : Values::bool($opcache['status']['opcache_enabled'] ?? $opcache['config']['directives']['opcache.enable'] ?? null)),
            new Recommendation('APCu enabled', $apcu === [] ? null : Values::bool($apcu['config']['apc.enabled'] ?? null)),
            new Recommendation('realpath_cache_size >= 4M', is_int($realpathSize) ? $realpathSize >= 4_194_304 : null, Values::string($php['realpath_cache_size'] ?? null)),
            new Recommendation('realpath_cache_ttl >= 600', $realpathTtl === null ? null : $realpathTtl >= 600, Values::string($php['realpath_cache_ttl'] ?? null)),
        ];
    }
}
