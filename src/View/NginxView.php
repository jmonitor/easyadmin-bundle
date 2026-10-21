<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Rate;
use Jmonitor\EasyAdminBundle\Metric\Ratios;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

final readonly class NginxView
{
    public ?string $version;
    public ?Badge $versionStatus;

    /** False when "nginx -T" gave no configuration. */
    public bool $configAvailable;
    public ?string $workerProcesses;
    public ?int $workerConnections;
    public ?int $maxConnections;
    public ?string $configPath;
    /** @var list<string> */
    public array $includes;
    public ?string $user;
    public ?string $accessLog;
    public ?string $errorLog;
    public ?string $errorLogLevel;
    public ?bool $sendfile;
    public ?bool $tcpNopush;
    public ?bool $tcpNodelay;
    public ?int $keepaliveTimeout;
    public Badge|string|null $gzip;
    public ?string $sslProtocols;
    public ?bool $sslPreferServerCiphers;
    public Badge|string|null $serverTokens;

    public Gauge $activeConnections;
    public ?int $active;
    public ?int $reading;
    public ?int $writing;
    public ?int $waiting;
    public Rate $requestsPerSec;
    public Rate $requestsPerConnection;
    /** Accepted connections Nginx did not handle, since it started. */
    public ?int $refused;
    public ?Badge $refusedStatus;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges, ?EndOfLife $endOfLife = null)
    {
        $data = $snapshot->data('nginx');
        $delta = $snapshot->delta('nginx');
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];

        $this->version = Values::string($data['version'] ?? null);
        $this->versionStatus = $endOfLife?->badge('nginx', $this->version);

        $this->configPath = Values::string($config['config_path'] ?? null);
        $this->configAvailable = $this->configPath !== null;
        $processes = Values::string($config['worker_processes'] ?? null);
        $cpus = Values::int($data['cpu_count'] ?? null);
        $this->workerProcesses = $processes === 'auto' && $cpus !== null ? sprintf('auto (%d CPUs)', $cpus) : $processes;
        $this->workerConnections = Values::int($config['worker_connections'] ?? null);
        $processCount = $processes === 'auto' ? $cpus : Values::int($processes);
        $this->maxConnections = $processCount === null || $this->workerConnections === null ? null : $processCount * $this->workerConnections;

        $this->includes = array_values(array_filter(is_array($config['include'] ?? null) ? $config['include'] : [], is_string(...)));
        $this->user = Values::string($config['user'] ?? null);
        $this->accessLog = Values::string($config['access_log'] ?? null);
        $errorLog = explode(' ', Values::string($config['error_log'] ?? null) ?? '', 2);
        $this->errorLog = $errorLog[0] === '' ? null : $errorLog[0];
        $this->errorLogLevel = Values::string(trim($errorLog[1] ?? ''));
        $this->sendfile = Values::bool($config['sendfile'] ?? null);
        $this->tcpNopush = Values::bool($config['tcp_nopush'] ?? null);
        $this->tcpNodelay = Values::bool($config['tcp_nodelay'] ?? null);
        $this->keepaliveTimeout = Values::int($config['keepalive_timeout'] ?? null);
        $this->gzip = self::flag($config['gzip'] ?? null, BadgeStyle::Success, BadgeStyle::Warning);
        $this->sslProtocols = Values::string($config['ssl_protocols'] ?? null);
        $this->sslPreferServerCiphers = Values::bool($config['ssl_prefer_server_ciphers'] ?? null);
        $this->serverTokens = self::flag($config['server_tokens'] ?? null, BadgeStyle::Warning, BadgeStyle::Success);

        $this->active = Values::int($status['active'] ?? null);
        $this->reading = Values::int($status['reading'] ?? null);
        $this->writing = Values::int($status['writing'] ?? null);
        $this->waiting = Values::int($status['waiting'] ?? null);
        $this->activeConnections = $gauges->create(
            'nginx.active_connections',
            'Active connections',
            Values::percent($this->active, $this->maxConnections),
            1,
            $this->active === null || $this->maxConnections === null ? null : $this->active . ' / ' . $this->maxConnections,
            $this->active !== null && $this->maxConnections === null ? 'connection limit unknown' : null,
        );

        $accepts = Values::int($status['accepts'] ?? null);
        $handled = Values::int($status['handled'] ?? null);
        $requests = Values::int($status['requests'] ?? null);
        $this->requestsPerSec = Ratios::perSecond($delta, 'status.requests', 'req/s', $requests);
        $this->requestsPerConnection = Ratios::average($delta, 'status.requests', 'status.handled', $requests, $handled, '');
        $this->refused = $accepts === null || $handled === null ? null : max(0, $accepts - $handled);
        $this->refusedStatus = match (true) {
            $this->refused === null => null,
            $this->refused === 0 => new Badge(BadgeStyle::Success, 'None'),
            default => new Badge(BadgeStyle::Danger, 'Limit reached'),
        };
    }

    /**
     * An "on"/"off" directive whose value is good or bad, any other value as written.
     */
    private static function flag(mixed $value, BadgeStyle $on, BadgeStyle $off): Badge|string|null
    {
        return match (Values::bool($value)) {
            true => new Badge($on, 'On'),
            false => new Badge($off, 'Off'),
            null => Values::string($value),
        };
    }
}
