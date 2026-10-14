<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class PhpView
{
    public bool $available;
    public ?string $version;
    public ?string $sapi;
    public ?string $iniFile;
    /** @var list<string> */
    public array $iniFiles;
    /** @var list<string> */
    public array $extensions;
    /** @var array<string, mixed> */
    public array $baseConfig;

    public ?bool $opcacheEnabled;
    public ?Gauge $opcacheMemory;
    public ?Gauge $opcacheHitRate;
    /** @var array<string, mixed> */
    public array $opcacheConfig;
    /** @var array<string, mixed> */
    public array $opcacheStats;

    public ?bool $apcuEnabled;
    public ?Gauge $apcuMemory;
    public ?Gauge $apcuHitRate;
    /** @var array<string, mixed> */
    public array $apcuStats;

    public bool $fpm;
    public ?Gauge $fpmActive;
    public ?Gauge $fpmMemoryPeak;
    /** @var array<string, mixed> */
    public array $fpmStats;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $data = $snapshot->data('php');
        $delta = $snapshot->delta('php');

        $this->available = $snapshot->has('php');
        $this->version = Values::string($data['version'] ?? null);
        $this->sapi = Values::string($data['sapi_name'] ?? null);
        $this->iniFile = Values::string($data['ini_file'] ?? null);
        $this->iniFiles = self::strings($data['ini_files'] ?? []);

        $extensions = self::strings($data['loaded_extensions'] ?? []);
        sort($extensions, SORT_FLAG_CASE | SORT_STRING);
        $this->extensions = $extensions;

        $this->baseConfig = [
            'memory_limit' => $data['memory_limit'] ?? null,
            'max_execution_time' => $data['max_execution_time'] ?? null,
            'max_input_time' => $data['max_input_time'] ?? null,
            'max_input_vars' => $data['max_input_vars'] ?? null,
            'post_max_size' => $data['post_max_size'] ?? null,
            'upload_max_filesize' => $data['upload_max_filesize'] ?? null,
            'display_errors' => Values::bool($data['display_errors'] ?? null),
            'display_startup_errors' => Values::bool($data['display_startup_errors'] ?? null),
            'log_errors' => Values::bool($data['log_errors'] ?? null),
            'error_log' => $data['error_log'] ?? null,
            'error_reporting' => ErrorReporting::describe(Values::int($data['error_reporting'] ?? null)),
            'date.timezone' => $data['date.timezone'] ?? null,
            'expose_php' => Values::bool($data['expose_php'] ?? null),
            'realpath_cache_size' => $data['realpath_cache_size'] ?? null,
            'realpath_cache_ttl' => $data['realpath_cache_ttl'] ?? null,
        ];

        $opcache = is_array($data['opcache'] ?? null) ? $data['opcache'] : [];
        $directives = $opcache['config']['directives'] ?? [];
        $status = $opcache['status'] ?? [];
        $memory = $status['memory_usage'] ?? [];
        $statistics = $status['opcache_statistics'] ?? [];

        $used = Values::int($memory['used_memory'] ?? null);
        $free = Values::int($memory['free_memory'] ?? null);
        $wasted = Values::int($memory['wasted_memory'] ?? null);
        $total = $used !== null && $free !== null && $wasted !== null ? $used + $free + $wasted : null;

        $this->opcacheEnabled = $opcache === [] ? null : Values::bool($status['opcache_enabled'] ?? $directives['opcache.enable'] ?? null);
        $this->opcacheMemory = $opcache === [] ? null : $gauges->create('php.opcache_memory', 'OPcache memory', Values::percent($used, $total));
        $this->opcacheHitRate = $opcache === [] ? null : $gauges->fromRate('php.opcache_hit_rate', 'OPcache hit rate', Ratios::share(
            $delta,
            'opcache.status.opcache_statistics.hits',
            'opcache.status.opcache_statistics.misses',
            Values::int($statistics['hits'] ?? null),
            Values::int($statistics['misses'] ?? null),
        ));
        $this->opcacheConfig = [
            'opcache.memory_consumption' => $directives['opcache.memory_consumption'] ?? null,
            'opcache.interned_strings_buffer' => $directives['opcache.interned_strings_buffer'] ?? null,
            'opcache.max_accelerated_files' => $directives['opcache.max_accelerated_files'] ?? null,
            'opcache.validate_timestamps' => Values::bool($directives['opcache.validate_timestamps'] ?? null),
            'opcache.revalidate_freq' => $directives['opcache.revalidate_freq'] ?? null,
            'opcache.jit' => $directives['opcache.jit'] ?? null,
            'opcache.jit_buffer_size' => $directives['opcache.jit_buffer_size'] ?? null,
            'opcache.enable_cli' => Values::bool($directives['opcache.enable_cli'] ?? null),
        ];
        $this->opcacheStats = [
            'Cached scripts' => $statistics['num_cached_scripts'] ?? null,
            'Cached keys' => $statistics['num_cached_keys'] ?? null,
            'Max cached keys' => $statistics['max_cached_keys'] ?? null,
            'Hits' => $statistics['hits'] ?? null,
            'Misses' => $statistics['misses'] ?? null,
            'Wasted memory' => $wasted,
            'OOM restarts' => $statistics['oom_restarts'] ?? null,
            'Hash restarts' => $statistics['hash_restarts'] ?? null,
            'Manual restarts' => $statistics['manual_restarts'] ?? null,
        ];

        $apcu = is_array($data['apcu'] ?? null) ? $data['apcu'] : [];
        $cacheInfo = $apcu['cache_info'] ?? [];
        $sma = $apcu['sma_info'] ?? [];
        $segments = Values::int($sma['num_seg'] ?? null);
        $segmentSize = Values::int($sma['seg_size'] ?? null);
        $apcuTotal = $segments !== null && $segmentSize !== null ? $segments * $segmentSize : null;
        $apcuAvailable = Values::int($sma['avail_mem'] ?? null);
        $apcuUsed = $apcuTotal !== null && $apcuAvailable !== null ? $apcuTotal - $apcuAvailable : null;

        $this->apcuEnabled = $apcu === [] ? null : Values::bool($apcu['config']['apc.enabled'] ?? null);
        $this->apcuMemory = $apcu === [] ? null : $gauges->create('php.apcu_memory_usage', 'APCu memory', Values::percent($apcuUsed, $apcuTotal), 1);
        $this->apcuHitRate = $apcu === [] ? null : $gauges->fromRate('php.apcu_hit_rate', 'APCu hit rate', Ratios::share(
            $delta,
            'apcu.cache_info.num_hits',
            'apcu.cache_info.num_misses',
            Values::int($cacheInfo['num_hits'] ?? null),
            Values::int($cacheInfo['num_misses'] ?? null),
        ));
        $this->apcuStats = [
            'Entries' => $cacheInfo['num_entries'] ?? null,
            'Hits' => $cacheInfo['num_hits'] ?? null,
            'Misses' => $cacheInfo['num_misses'] ?? null,
            'Inserts' => $cacheInfo['num_inserts'] ?? null,
            'Expunges' => $cacheInfo['expunges'] ?? null,
            'Used memory' => $apcuUsed,
            'Total memory' => $apcuTotal,
        ];

        $fpm = is_array($data['fpm'] ?? null) ? $data['fpm'] : [];
        $active = Values::int($fpm['active-processes'] ?? null);
        $idle = Values::int($fpm['idle-processes'] ?? null);
        $processes = $active !== null && $idle !== null ? $active + $idle : null;
        $memoryLimit = Values::iniBytes($data['memory_limit'] ?? null);
        $peak = Values::int($fpm['memory-peak'] ?? null);

        $this->fpm = $fpm !== [];
        $this->fpmActive = $this->fpm ? $gauges->create('php.fpm_active_processes', 'Active processes', Values::percent($active, $processes)) : null;
        $this->fpmMemoryPeak = $this->fpm && is_int($memoryLimit) && $peak !== null
            ? $gauges->create('php.fpm_memory_peak_percent', 'Memory peak', Values::percent($peak, $memoryLimit))
            : null;
        $this->fpmStats = [
            'Pool' => $fpm['pool'] ?? null,
            'Process manager' => $fpm['process-manager'] ?? null,
            'Uptime' => $fpm['start-since'] ?? null,
            'Accepted connections' => $fpm['accepted-conn'] ?? null,
            'Listen queue' => $fpm['listen-queue'] ?? null,
            'Max listen queue' => $fpm['max-listen-queue'] ?? null,
            'Idle processes' => $idle,
            'Active processes' => $active,
            'Max active processes' => $fpm['max-active-processes'] ?? null,
            'Max children reached' => $fpm['max-children-reached'] ?? null,
            'Slow requests' => $fpm['slow-requests'] ?? null,
            'Memory peak' => $peak,
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(Values::string(...), $values), static fn(?string $value): bool => $value !== null));
    }
}
