<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Metric\Breakdown;
use Jmonitor\EasyAdminBundle\Metric\Gauge;
use Jmonitor\EasyAdminBundle\Metric\GaugeFactory;
use Jmonitor\EasyAdminBundle\Metric\Quota;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;

/**
 * Each block of the payload is read on its own: has() tells whether its cards can be drawn,
 * notice() why not when the token cannot read it or the account does not use the product.
 * A block that failed has neither, its error shows above the cards.
 */
final readonly class CloudflareView
{
    private const BLOCKS = ['zone', 'traffic', 'today', 'web_analytics', 'r2', 'images'];

    private const PERMISSIONS = [
        'zone' => 'Zone › Zone › Read',
        'traffic' => 'Zone › Analytics › Read',
        'today' => 'Zone › Analytics › Read',
        'web_analytics' => 'Account › Account Analytics › Read',
        'r2' => 'Account › Workers R2 Storage › Read, Account › Account Analytics › Read',
        'images' => 'Account › Images › Read, Account › Account Analytics › Read',
    ];

    private const NOT_CONFIGURED = [
        'web_analytics' => 'Web Analytics is not enabled on this zone.',
        'r2' => 'No R2 bucket on this account.',
        'images' => 'Cloudflare Images is not used on this account.',
    ];

    private const CERTIFICATE_WARNING_DAYS = 14;

    private const R2_FREE_STORAGE_BYTES = 10 * 1024 ** 3;
    private const R2_FREE_CLASS_A_OPERATIONS = 1_000_000;
    private const R2_FREE_CLASS_B_OPERATIONS = 10_000_000;
    private const IMAGES_FREE_UNIQUE_TRANSFORMATIONS = 5000;

    /** @var array<string, bool> block => whether its data was read */
    private array $read;
    /** @var array<string, string> block => why it has no data */
    private array $notices;

    public ?string $zoneName;
    public ?Badge $zoneStatus;
    public ?string $plan;
    public Badge $developmentMode;
    public ?int $developmentModeEndsAt;
    public ?string $dnsRecords;

    public ?string $certificateNotice;
    public ?int $certificateDaysLeft;
    public ?Badge $certificateStatus;
    public ?string $certificateIssuer;
    public ?string $certificateExpiresOn;
    /** @var list<string> */
    public array $certificateHosts;

    public ?string $settingsNotice;
    public ?Badge $sslMode;
    public ?Badge $alwaysUseHttps;
    public ?string $minTlsVersion;
    public ?string $securityLevel;
    public ?string $cacheLevel;
    /** Seconds, 0 when the headers of the origin decide. */
    public ?int $browserCacheTtl;

    public int $windowMinutes;
    public ?int $requests;
    public ?float $requestsPerMinute;
    public bool $sampled;
    public Gauge $cacheHitRatio;
    /** Seconds, for the latest minute that reached the origin. */
    public ?float $originP50;
    public ?float $originP95;
    public int $mitigated;
    public int $blocked;
    public int $challenged;
    public ?float $mitigatedShare;
    public Breakdown $byCacheStatus;
    public Breakdown $byStatus;
    public Breakdown $byHost;
    public Breakdown $byCountry;
    public Breakdown $securityByAction;
    public Breakdown $securityBySource;

    public ?int $todayRequests;
    public ?float $todayCachedPercent;
    public ?int $todayPageViews;
    public ?int $todayUniques;
    public ?int $todayBytes;
    public ?int $todayCachedBytes;
    public ?int $todayThreats;
    public ?float $todayThreatsPercent;

    /** @var list<CloudflareVital> */
    public array $vitals;
    public ?int $pageLoads;
    public ?int $visits;
    public ?float $pageLoadP50;
    public ?float $pageLoadP75;
    public Breakdown $pageLoadsByHost;
    /** @var array<string, ?float> step => seconds */
    public array $timings;

    /** @var list<Quota> */
    public array $r2Quotas;
    public ?string $r2ResetsOn;
    /** @var list<array{name: string, bytes: int, objects: int, storage_class: ?string}> */
    public array $buckets;

    /** @var list<Quota> */
    public array $imagesQuotas;
    public ?int $imagesRequests;
    public ?string $imagesResetsOn;

    public function __construct(ComponentSnapshot $snapshot, GaugeFactory $gauges, int $now)
    {
        $read = [];
        $notices = [];
        $data = [];

        foreach (self::BLOCKS as $block) {
            $payload = $snapshot->has('cloudflare.' . $block) ? $snapshot->data('cloudflare.' . $block) : [];
            $reason = Values::string($payload['unavailable'] ?? null);
            $read[$block] = $payload !== [] && $reason === null;
            $data[$block] = $read[$block] ? $payload : [];

            if ($reason !== null) {
                $notices[$block] = $reason === 'not_configured' && isset(self::NOT_CONFIGURED[$block])
                    ? self::NOT_CONFIGURED[$block]
                    : sprintf('The API token lacks a permission for this data: %s.', self::PERMISSIONS[$block]);
            }
        }

        $this->read = $read;
        $this->notices = $notices;

        $zone = $data['zone'];
        $collectedAt = $snapshot->result('cloudflare.zone')?->collectedAt;
        $this->zoneName = Values::string($zone['name'] ?? null);
        $status = Values::string($zone['status'] ?? null);
        $this->zoneStatus = match (true) {
            Values::bool($zone['paused'] ?? null) === true => new Badge(BadgeStyle::Danger, 'paused'),
            $status === null => null,
            default => new Badge($status === 'active' ? BadgeStyle::Success : BadgeStyle::Danger, $status),
        };
        $plan = Values::string($zone['plan'] ?? null);
        $this->plan = $plan === null ? null : ucfirst($plan);

        $developmentMode = Values::int($zone['development_mode'] ?? null) ?? 0;
        $this->developmentModeEndsAt = $developmentMode > 0 && $collectedAt !== null && $collectedAt + $developmentMode > $now ? $collectedAt + $developmentMode : null;
        $this->developmentMode = $this->developmentModeEndsAt === null ? new Badge(BadgeStyle::Neutral, 'Off') : new Badge(BadgeStyle::Danger, 'On');

        $used = Values::int($zone['dns_records']['used'] ?? null);
        $quota = Values::int($zone['dns_records']['quota'] ?? null);
        $this->dnsRecords = $used === null || $quota === null ? null : $used . ' / ' . $quota;

        $certificate = is_array($zone['certificate'] ?? null) ? $zone['certificate'] : null;
        $this->certificateNotice = $zone === [] || $certificate !== null ? null : 'No active edge certificate on the zone, or the API token lacks the Zone › SSL and Certificates › Read permission.';
        $expiresAt = Values::int($certificate['expires_at'] ?? null);
        $this->certificateDaysLeft = $expiresAt === null ? null : (int) floor(($expiresAt - $now) / 86400);
        $this->certificateExpiresOn = $expiresAt === null ? null : gmdate('Y-m-d', $expiresAt);
        $this->certificateIssuer = Values::string($certificate['issuer'] ?? null);
        $this->certificateHosts = array_values(array_filter(is_array($certificate['hosts'] ?? null) ? $certificate['hosts'] : [], is_string(...)));
        $certificateStatus = Values::string($certificate['status'] ?? null);
        $this->certificateStatus = match (true) {
            $certificate === null => null,
            $this->certificateDaysLeft !== null && $this->certificateDaysLeft < 0 => new Badge(BadgeStyle::Danger, 'expired'),
            $certificateStatus !== 'active' => new Badge(BadgeStyle::Danger, $certificateStatus ?? 'unknown'),
            $this->certificateDaysLeft !== null && $this->certificateDaysLeft <= self::CERTIFICATE_WARNING_DAYS => new Badge(BadgeStyle::Warning, 'expires soon'),
            default => new Badge(BadgeStyle::Success, 'active'),
        };

        $settings = is_array($zone['settings'] ?? null) ? $zone['settings'] : null;
        $this->settingsNotice = $zone === [] || $settings !== null ? null : 'The settings could not be read: check that the API token has the Zone › Zone Settings › Read permission.';
        $ssl = Values::string($settings['ssl'] ?? null);
        $this->sslMode = $ssl === null ? null : new Badge(match ($ssl) {
            'full', 'strict' => BadgeStyle::Success,
            'flexible', 'off' => BadgeStyle::Warning,
            default => BadgeStyle::Neutral,
        }, $ssl);
        $https = Values::string($settings['always_use_https'] ?? null);
        $this->alwaysUseHttps = $https === null ? null : new Badge($https === 'on' ? BadgeStyle::Success : BadgeStyle::Neutral, $https);
        $this->minTlsVersion = Values::string($settings['min_tls_version'] ?? null);
        $securityLevel = Values::string($settings['security_level'] ?? null);
        $this->securityLevel = $securityLevel === null ? null : ucfirst(str_replace('_', ' ', $securityLevel));
        $cacheLevel = Values::string($settings['cache_level'] ?? null);
        $this->cacheLevel = $cacheLevel === null ? null : ucfirst($cacheLevel);
        $this->browserCacheTtl = Values::int($settings['browser_cache_ttl'] ?? null);

        $traffic = $data['traffic'];
        $window = is_array($traffic['window'] ?? null) ? $traffic['window'] : [];
        $seconds = max(0, (Values::int($window['to'] ?? null) ?? 0) - (Values::int($window['from'] ?? null) ?? 0));
        $this->windowMinutes = intdiv($seconds, 60);
        $this->requests = Values::int($window['requests'] ?? null);
        $this->requestsPerMinute = $this->requests === null || $seconds === 0 ? null : $this->requests / ($seconds / 60);
        $this->sampled = Values::bool($window['sampled'] ?? null) ?? false;

        $byCacheStatus = self::counts($window['by_cache_status'] ?? null);
        $cached = array_sum(array_filter($byCacheStatus, static fn(int|string $status): bool => CloudflareLabels::isCached((string) $status), ARRAY_FILTER_USE_KEY));
        $this->cacheHitRatio = $gauges->create('cloudflare.cache_hit_ratio', 'Cache hit ratio', Values::percent($cached, $this->requests), 1, $this->requests === null ? null : $cached . ' / ' . $this->requests . ' requests');

        $latest = null;
        foreach (array_reverse(is_array($traffic['minutes'] ?? null) ? $traffic['minutes'] : []) as $minute) {
            if (is_array($minute) && Values::float($minute['origin_response_ms_p50'] ?? null) !== null) {
                $latest = $minute;
                break;
            }
        }

        $this->originP50 = self::seconds($latest['origin_response_ms_p50'] ?? null);
        $this->originP95 = self::seconds($latest['origin_response_ms_p95'] ?? null);

        // Cloudflare files the requests no security feature acted on under "unknown".
        $security = is_array($window['security'] ?? null) ? $window['security'] : [];
        $byAction = array_diff_key(self::counts($security['by_action'] ?? null), ['unknown' => 0]);
        $bySource = array_diff_key(self::counts($security['by_source'] ?? null), ['unknown' => 0]);
        $blocks = array_filter($byAction, static fn(int|string $action): bool => CloudflareLabels::isBlock((string) $action), ARRAY_FILTER_USE_KEY);
        $challenges = array_filter($byAction, static fn(int|string $action): bool => CloudflareLabels::isChallenge((string) $action), ARRAY_FILTER_USE_KEY);
        $mitigations = $blocks + $challenges;
        $passive = array_diff_key($byAction, $mitigations);
        arsort($mitigations);
        arsort($passive);

        $this->blocked = array_sum($blocks);
        $this->challenged = array_sum($challenges);
        $this->mitigated = $this->blocked + $this->challenged;
        $this->mitigatedShare = Values::percent($this->mitigated, $this->requests);

        $statusClasses = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, Breakdown::OTHER => 0];
        foreach (self::counts($window['by_status'] ?? null) as $status => $count) {
            $class = intdiv((int) $status, 100) . 'xx';
            $statusClasses[isset($statusClasses[$class]) ? $class : Breakdown::OTHER] += $count;
        }

        if ($statusClasses[Breakdown::OTHER] === 0) {
            unset($statusClasses[Breakdown::OTHER]);
        }

        $this->byCacheStatus = Breakdown::of(Breakdown::top($byCacheStatus), ucfirst(...), CloudflareLabels::cacheStatusStyle(...));
        $this->byStatus = Breakdown::of(array_sum($statusClasses) > 0 ? $statusClasses : [], style: CloudflareLabels::statusClassStyle(...));
        // Cloudflare lists the largest hosts and countries only: their shares are of every request.
        $this->byHost = Breakdown::of(Breakdown::top(self::counts($window['by_host'] ?? null), total: $this->requests));
        $this->byCountry = Breakdown::of(Breakdown::top(self::counts($window['by_country'] ?? null), total: $this->requests), CloudflareLabels::country(...));
        $this->securityByAction = Breakdown::of($mitigations + $passive, CloudflareLabels::action(...), CloudflareLabels::actionStyle(...));
        $this->securityBySource = Breakdown::of(Breakdown::top($bySource), CloudflareLabels::source(...));

        $today = $data['today'];
        $this->todayRequests = Values::int($today['requests'] ?? null);
        $this->todayCachedPercent = Values::percent(Values::int($today['cached_requests'] ?? null), $this->todayRequests);
        $this->todayPageViews = Values::int($today['page_views'] ?? null);
        $this->todayUniques = Values::int($today['uniques'] ?? null);
        $this->todayBytes = Values::int($today['bytes'] ?? null);
        $this->todayCachedBytes = Values::int($today['cached_bytes'] ?? null);
        $this->todayThreats = Values::int($today['threats'] ?? null);
        $this->todayThreatsPercent = Values::percent($this->todayThreats, $this->todayRequests);

        $analytics = $data['web_analytics'];
        $vitals = is_array($analytics['vitals'] ?? null) ? $analytics['vitals'] : [];
        $timings = is_array($analytics['timings'] ?? null) ? $analytics['timings'] : [];

        $this->vitals = [
            new CloudflareVital('LCP', self::seconds($vitals['lcp_p75_ms'] ?? null)),
            new CloudflareVital('INP', self::seconds($vitals['inp_p75_ms'] ?? null)),
            new CloudflareVital('CLS', Values::float($vitals['cls_p75'] ?? null), isDuration: false),
            new CloudflareVital('TTFB', self::seconds($vitals['ttfb_p75_ms'] ?? null)),
            new CloudflareVital('FCP', self::seconds($vitals['fcp_p75_ms'] ?? null)),
        ];
        $this->pageLoads = Values::int($analytics['page_views'] ?? null);
        $this->visits = Values::int($analytics['visits'] ?? null);
        $this->pageLoadP50 = self::seconds($timings['page_load_p50_ms'] ?? null);
        $this->pageLoadP75 = self::seconds($timings['page_load_p75_ms'] ?? null);
        $this->pageLoadsByHost = Breakdown::of(Breakdown::top(self::counts($analytics['by_host'] ?? null), total: $this->pageLoads));
        $this->timings = [
            'DNS' => self::seconds($timings['dns_p75_ms'] ?? null),
            'Connection' => self::seconds($timings['connection_p75_ms'] ?? null),
            'Request' => self::seconds($timings['request_p75_ms'] ?? null),
            'Response' => self::seconds($timings['response_p75_ms'] ?? null),
            'Page render' => self::seconds($timings['page_render_p75_ms'] ?? null),
        ];

        $r2 = $data['r2'];
        $buckets = [];

        foreach (is_array($r2['buckets'] ?? null) ? $r2['buckets'] : [] as $bucket) {
            if (is_array($bucket) && is_string($bucket['name'] ?? null)) {
                $buckets[] = [
                    'name' => $bucket['name'],
                    'bytes' => (Values::int($bucket['payload_bytes'] ?? null) ?? 0) + (Values::int($bucket['metadata_bytes'] ?? null) ?? 0),
                    'objects' => Values::int($bucket['object_count'] ?? null) ?? 0,
                    'storage_class' => Values::string($bucket['storage_class'] ?? null),
                ];
            }
        }

        $this->buckets = $buckets;
        $this->r2ResetsOn = self::nextMonth($r2['month'] ?? null);
        $this->r2Quotas = $r2 === [] ? [] : [
            Quota::bytes('Storage', array_sum(array_column($buckets, 'bytes')), self::R2_FREE_STORAGE_BYTES),
            Quota::count('Class A operations', Values::int($r2['class_a_operations'] ?? null) ?? 0, self::R2_FREE_CLASS_A_OPERATIONS),
            Quota::count('Class B operations', Values::int($r2['class_b_operations'] ?? null) ?? 0, self::R2_FREE_CLASS_B_OPERATIONS),
        ];

        $images = $data['images'];
        $quotas = $images === [] ? [] : [Quota::count('Unique transformations', Values::int($images['unique_transformations'] ?? null) ?? 0, self::IMAGES_FREE_UNIQUE_TRANSFORMATIONS)];
        $storedQuota = Values::int($images['stored_quota'] ?? null);

        if ($storedQuota !== null && $storedQuota > 0) {
            $quotas[] = Quota::limit('Stored images', Values::int($images['stored'] ?? null) ?? 0, $storedQuota);
        }

        $this->imagesQuotas = $quotas;
        $this->imagesRequests = Values::int($images['requests_last_24h'] ?? null);
        $this->imagesResetsOn = self::nextMonth($images['month'] ?? null);
    }

    public function has(string $block): bool
    {
        return $this->read[$block] ?? false;
    }

    public function notice(string $block): ?string
    {
        return $this->notices[$block] ?? null;
    }

    /**
     * False when the account uses neither R2 nor Images, or when neither could be read.
     */
    public function showsStorage(): bool
    {
        $shown = array_filter(['r2', 'images'], fn(string $block): bool => $this->has($block) || ($this->notice($block) !== null && $this->notice($block) !== self::NOT_CONFIGURED[$block]));

        return $shown !== [];
    }

    /**
     * @return array<array-key, int>
     */
    private static function counts(mixed $values): array
    {
        return array_map(static fn(mixed $count): int => Values::int($count) ?? 0, is_array($values) ? $values : []);
    }

    private static function seconds(mixed $milliseconds): ?float
    {
        $value = Values::float($milliseconds);

        return $value === null ? null : $value / 1000;
    }

    /**
     * The first day of the month after a "YYYY-MM" one, when its counters start again.
     */
    private static function nextMonth(mixed $month): ?string
    {
        $start = is_string($month) ? \DateTimeImmutable::createFromFormat('!Y-m', $month, new \DateTimeZone('UTC')) : false;

        return $start === false ? null : $start->modify('+1 month')->format('Y-m-d');
    }
}
