<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class PhpView
{
    public bool $available;
    public ?string $version;
    public ?string $sapi;

    public ?int $memoryLimit;
    public ?int $maxExecutionTime;
    public Badge|string|null $displayErrors;
    public ?Badge $logErrors;
    public ?string $errorLog;
    public ?string $errorReporting;
    public ?string $postMaxSize;
    public ?string $uploadMaxFilesize;
    public ?string $timezone;

    /** @var list<string> */
    public array $iniFiles;
    /** @var list<string> */
    public array $extensions;

    public bool $opcache;
    public Badge $opcacheEnabled;
    public Badge $opcacheCli;
    public Gauge $opcacheMemory;
    public ?int $opcacheUsed;
    public ?int $opcacheFree;
    public ?int $opcacheWasted;
    public ?int $opcacheTotal;
    public Gauge $opcacheHitRate;
    public ?int $opcacheMemoryConsumption;
    public Badge $opcachePreload;
    public Badge|string|null $opcacheRevalidateFreq;
    public ?int $opcacheMaxAcceleratedFiles;

    public bool $apcu;
    public Badge $apcuEnabled;
    public Badge $apcuCli;
    public Gauge $apcuMemory;
    public ?int $apcuUsed;
    public ?int $apcuTotal;
    public Gauge $apcuHitRate;
    public int|false|null $apcuShmSize;
    public ?int $apcuShmSegments;
    public ?int $apcuTtl;

    public bool $fpm;
    public Rate $fpmRequestsPerSec;
    public Gauge $fpmActiveProcesses;
    public ?int $fpmActive;
    public ?int $fpmProcesses;
    public ?int $fpmIdle;
    public ?int $fpmMaxChildrenReached;
    public ?Badge $fpmMaxChildrenReachedBadge;
    public ?int $fpmSlowRequests;
    public ?Badge $fpmSlowRequestsBadge;
    public ?Gauge $fpmMemoryPeakPercent;
    public ?int $fpmMemoryPeak;
    public ?string $fpmPool;
    public ?string $fpmProcessManager;
    public ?int $fpmUptime;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges)
    {
        $data = $snapshot->data('php');
        $delta = $snapshot->delta('php');

        $this->available = $snapshot->has('php');
        $this->version = Values::string($data['version'] ?? null);
        $this->sapi = Values::string($data['sapi_name'] ?? null);

        $memoryLimit = Values::iniBytes($data['memory_limit'] ?? null);
        $maxExecutionTime = Values::int($data['max_execution_time'] ?? null);
        $this->memoryLimit = is_int($memoryLimit) ? $memoryLimit : null;
        $this->maxExecutionTime = $maxExecutionTime === 0 ? null : $maxExecutionTime;
        $this->displayErrors = self::displayErrors($data['display_errors'] ?? null);
        $this->logErrors = match (Values::bool($data['log_errors'] ?? null)) {
            true => new Badge(BadgeStyle::Success, '1'),
            false => new Badge(BadgeStyle::Warning, '0'),
            null => null,
        };
        $this->errorLog = Values::string($data['error_log'] ?? null);
        $this->errorReporting = ErrorReporting::describe(Values::int($data['error_reporting'] ?? null));
        $this->postMaxSize = Values::string($data['post_max_size'] ?? null);
        $this->uploadMaxFilesize = Values::string($data['upload_max_filesize'] ?? null);
        $this->timezone = Values::string($data['date.timezone'] ?? null);

        $iniFile = Values::string($data['ini_file'] ?? null);
        $this->iniFiles = array_merge($iniFile === null ? [] : [$iniFile], self::strings($data['ini_files'] ?? []));
        $this->extensions = self::strings($data['loaded_extensions'] ?? []);

        $opcache = is_array($data['opcache'] ?? null) ? $data['opcache'] : [];
        $directives = $opcache['config']['directives'] ?? [];
        $memory = $opcache['status']['memory_usage'] ?? [];
        $statistics = $opcache['status']['opcache_statistics'] ?? [];

        $this->opcache = $opcache !== [];
        $this->opcacheEnabled = Values::bool($directives['opcache.enable'] ?? null) === true
            ? new Badge(BadgeStyle::Success, 'Enabled')
            : new Badge(BadgeStyle::Danger, 'Disabled');
        $this->opcacheCli = Values::bool($directives['opcache.enable_cli'] ?? null) === true
            ? new Badge(BadgeStyle::Success, 'CLI: enabled')
            : new Badge(BadgeStyle::Neutral, 'CLI: disabled');
        $this->opcacheUsed = Values::int($memory['used_memory'] ?? null);
        $this->opcacheFree = Values::int($memory['free_memory'] ?? null);
        $this->opcacheWasted = Values::int($memory['wasted_memory'] ?? null);
        $this->opcacheTotal = $this->opcacheUsed !== null && $this->opcacheFree !== null && $this->opcacheWasted !== null
            ? $this->opcacheUsed + $this->opcacheFree + $this->opcacheWasted
            : null;
        $this->opcacheMemory = $gauges->create('php.opcache_memory', 'Memory usage', Values::percent($this->opcacheUsed, $this->opcacheTotal));
        $this->opcacheHitRate = $gauges->fromRate('php.opcache_hit_rate', 'Hit rate', Ratios::share(
            $delta,
            'opcache.status.opcache_statistics.hits',
            'opcache.status.opcache_statistics.misses',
            Values::int($statistics['hits'] ?? null),
            Values::int($statistics['misses'] ?? null),
        ));
        $this->opcacheMemoryConsumption = Values::int($directives['opcache.memory_consumption'] ?? null);
        $this->opcachePreload = Values::string($directives['opcache.preload'] ?? null) === null
            ? new Badge(BadgeStyle::Neutral, 'Disabled')
            : new Badge(BadgeStyle::Success, 'Active');
        $revalidateFreq = Values::int($directives['opcache.revalidate_freq'] ?? null);
        $this->opcacheRevalidateFreq = match (true) {
            Values::bool($directives['opcache.validate_timestamps'] ?? null) !== true => new Badge(BadgeStyle::Success, 'Disabled'),
            $revalidateFreq === null, $revalidateFreq === 0 => null,
            default => $revalidateFreq . 's',
        };
        $this->opcacheMaxAcceleratedFiles = Values::int($directives['opcache.max_accelerated_files'] ?? null);

        $apcu = is_array($data['apcu'] ?? null) ? $data['apcu'] : [];
        $apcuConfig = $apcu['config'] ?? [];
        $cacheInfo = $apcu['cache_info'] ?? [];
        $sma = $apcu['sma_info'] ?? [];
        $segments = Values::int($sma['num_seg'] ?? null);
        $segmentSize = Values::int($sma['seg_size'] ?? null);
        $available = Values::int($sma['avail_mem'] ?? null);

        $this->apcu = $apcu !== [];
        $this->apcuEnabled = Values::bool($apcuConfig['apc.enabled'] ?? null) === true
            ? new Badge(BadgeStyle::Success, 'Enabled')
            : new Badge(BadgeStyle::Danger, 'Disabled');
        $this->apcuCli = Values::bool($apcuConfig['apc.enable_cli'] ?? null) === true
            ? new Badge(BadgeStyle::Warning, 'CLI: enabled')
            : new Badge(BadgeStyle::Neutral, 'CLI: disabled');
        $this->apcuTotal = $segments !== null && $segmentSize !== null ? $segments * $segmentSize : null;
        $this->apcuUsed = $this->apcuTotal !== null && $available !== null ? $this->apcuTotal - $available : null;
        $this->apcuMemory = $gauges->create('php.apcu_memory_usage', 'Memory usage', Values::percent($this->apcuUsed, $this->apcuTotal), 1);
        $this->apcuHitRate = $gauges->fromRate('php.apcu_hit_rate', 'Hit rate', Ratios::share(
            $delta,
            'apcu.cache_info.num_hits',
            'apcu.cache_info.num_misses',
            Values::int($cacheInfo['num_hits'] ?? null),
            Values::int($cacheInfo['num_misses'] ?? null),
        ));
        $this->apcuShmSize = Values::iniBytes($apcuConfig['apc.shm_size'] ?? null);
        $this->apcuShmSegments = Values::int($apcuConfig['apc.shm_segments'] ?? null);
        $this->apcuTtl = Values::int($apcuConfig['apc.ttl'] ?? null);

        $fpm = is_array($data['fpm'] ?? null) ? $data['fpm'] : [];
        $this->fpm = $fpm !== [];
        $this->fpmActive = Values::int($fpm['active-processes'] ?? null);
        $this->fpmIdle = Values::int($fpm['idle-processes'] ?? null);
        $this->fpmProcesses = $this->fpmActive !== null && $this->fpmIdle !== null ? $this->fpmActive + $this->fpmIdle : null;
        $this->fpmRequestsPerSec = Ratios::perSecond($delta, 'fpm.accepted-conn', 'req/s');
        $this->fpmActiveProcesses = $gauges->create('php.fpm_active_processes', 'Active processes', Values::percent($this->fpmActive, $this->fpmProcesses), 2);
        $this->fpmMaxChildrenReached = Values::int($fpm['max-children-reached'] ?? null);
        $this->fpmMaxChildrenReachedBadge = match (true) {
            $this->fpmMaxChildrenReached === null => null,
            $this->fpmMaxChildrenReached === 0 => new Badge(BadgeStyle::Success, 'Never reached'),
            default => new Badge(BadgeStyle::Danger, 'Reached'),
        };
        $this->fpmSlowRequests = Values::int($fpm['slow-requests'] ?? null);
        $this->fpmSlowRequestsBadge = match (true) {
            $this->fpmSlowRequests === null => null,
            $this->fpmSlowRequests === 0 => new Badge(BadgeStyle::Success, 'None'),
            default => new Badge(BadgeStyle::Danger, 'Detected'),
        };
        $this->fpmMemoryPeak = Values::int($fpm['memory-peak'] ?? null);
        $this->fpmMemoryPeakPercent = $memoryLimit === false
            ? null
            : $gauges->create('php.fpm_memory_peak_percent', 'Memory peak', Values::percent($this->fpmMemoryPeak, $this->memoryLimit));
        $this->fpmPool = Values::string($fpm['pool'] ?? null);
        $this->fpmProcessManager = Values::string($fpm['process-manager'] ?? null);
        $this->fpmUptime = Values::int($fpm['start-since'] ?? null);
    }

    private static function displayErrors(mixed $value): Badge|string|null
    {
        if (is_string($value) && strtolower($value) === 'stderr') {
            return $value;
        }

        return match (Values::bool($value)) {
            true => new Badge(BadgeStyle::Warning, '1'),
            false => new Badge(BadgeStyle::Success, '0'),
            null => null,
        };
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
