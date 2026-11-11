<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Cloudflare;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers the Cloudflare REST and GraphQL calls of one zone, on a Free plan with Web Analytics, one R2
 * bucket and Images. A route is "GET /zones/{zone}/settings" or "GraphQL Traffic".
 */
final class FixtureCloudflareClient implements ClientInterface
{
    public const ZONE_ID = '0123456789abcdef0123456789abcdef';
    public const ZONE = 'example.com';
    public const ACCOUNT_ID = 'fedcba9876543210fedcba9876543210';
    /** The time the Cloudflare payloads of Payloads were read at. */
    public const NOW = '2026-11-11 12:00:30 UTC';

    /** @var list<string> the routes asked for */
    public array $routes = [];

    /**
     * @param array<string, int> $statuses route => HTTP status answered instead of the data
     * @param list<string>       $empty    routes answering that there is nothing: no bucket, no Web Analytics site, no usage
     * @param bool               $down     fails every request like a refused connection
     */
    public function __construct(
        private readonly array $statuses = [],
        private readonly array $empty = [],
        private readonly bool $down = false,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = str_replace('/client/v4', '', $request->getUri()->getPath());
        $payload = json_decode((string) $request->getBody(), true);
        $variables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        $route = $path === '/graphql'
            ? 'GraphQL ' . (preg_match('/query\s+(\w+)/', is_array($payload) ? (string) ($payload['query'] ?? '') : '', $m) === 1 ? $m[1] : '?')
            : $request->getMethod() . ' ' . str_replace([self::ZONE_ID, self::ACCOUNT_ID], ['{zone}', '{account}'], $path);

        $this->routes[] = $route;

        if ($this->down) {
            throw new class ('Connection refused for "' . $request->getUri() . '".', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }

        if (isset($this->statuses[$route])) {
            return self::json($this->statuses[$route], ['success' => false, 'errors' => [['message' => 'Refused by the fixture']]]);
        }

        if (str_starts_with($route, 'GraphQL ')) {
            return self::json(200, ['data' => ['viewer' => $this->graphql($route, $variables)], 'errors' => null]);
        }

        $body = $this->rest($route);

        return $body === null
            ? self::json(404, ['success' => false, 'errors' => [['message' => 'No such route in the fixture: ' . $route]]])
            : self::json(200, ['success' => true] + $body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rest(string $route): ?array
    {
        $zone = [
            'id' => self::ZONE_ID,
            'name' => self::ZONE,
            'account' => ['id' => self::ACCOUNT_ID],
            'plan' => ['legacy_id' => 'free'],
            'status' => 'active',
            'paused' => false,
            'development_mode' => 0,
        ];
        $nothing = in_array($route, $this->empty, true);

        return match ($route) {
            'GET /zones' => ['result' => $nothing ? [] : [$zone]],
            'GET /zones/{zone}' => ['result' => $zone],
            'GET /zones/{zone}/settings' => ['result' => [
                ['id' => 'ssl', 'value' => 'strict'],
                ['id' => 'always_use_https', 'value' => 'on'],
                ['id' => 'min_tls_version', 'value' => '1.2'],
                ['id' => 'security_level', 'value' => 'medium'],
                ['id' => 'cache_level', 'value' => 'aggressive'],
                ['id' => 'browser_cache_ttl', 'value' => 14400],
                ['id' => 'rocket_loader', 'value' => 'off'],
            ]],
            'GET /zones/{zone}/ssl/certificate_packs' => ['result' => $nothing ? [] : [[
                'type' => 'universal',
                'status' => 'active',
                'primary_certificate' => 'cert-1',
                'certificates' => [[
                    'id' => 'cert-1',
                    'status' => 'active',
                    'issuer' => 'GoogleTrustServices',
                    'expires_on' => '2027-01-10T00:00:00Z',
                    'hosts' => ['example.com', '*.example.com'],
                ]],
            ]]],
            'GET /zones/{zone}/dns_records/usage' => ['result' => ['record_usage' => 12, 'record_quota' => 200]],
            'GET /accounts/{account}/rum/site_info/list' => [
                'result' => $nothing ? [] : [['site_tag' => 'site-1', 'ruleset' => ['zone_tag' => self::ZONE_ID]]],
                'result_info' => ['per_page' => 10, 'total_count' => $nothing ? 0 : 1],
            ],
            'GET /accounts/{account}/r2/buckets' => ['result' => ['buckets' => $nothing ? [] : [['name' => 'assets', 'creation_date' => '2026-01-10T00:00:00Z']]]],
            'GET /accounts/{account}/images/v1/stats' => ['result' => ['count' => ['current' => 120, 'allowed' => 100_000]]],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function graphql(string $route, array $variables): array
    {
        $nothing = in_array($route, $this->empty, true);
        $to = is_string($variables['to'] ?? null) ? (int) strtotime($variables['to']) : 0;
        $minute = static fn(int $ago): string => gmdate('Y-m-d\TH:i:s\Z', $to - $ago * 60);

        return match ($route) {
            'GraphQL Traffic' => ['zones' => [[
                'minutes' => [
                    ['dimensions' => ['datetimeMinute' => $minute(2)], 'sum' => ['requests' => 120, 'cachedRequests' => 60, 'bytes' => 2_400_000, 'cachedBytes' => 1_200_000, 'pageViews' => 30, 'visits' => 12], 'ratio' => ['status4xx' => 0.05, 'status5xx' => 0.0], 'avg' => ['originResponseDurationMs' => 140.0]],
                    ['dimensions' => ['datetimeMinute' => $minute(1)], 'sum' => ['requests' => 80, 'cachedRequests' => 80, 'bytes' => 1_600_000, 'cachedBytes' => 1_600_000, 'pageViews' => 20, 'visits' => 8], 'ratio' => ['status4xx' => 0.0, 'status5xx' => 0.0], 'avg' => ['originResponseDurationMs' => -1]],
                ],
                'origin' => [
                    ['dimensions' => ['datetimeMinute' => $minute(2)], 'count' => 60, 'quantiles' => ['originResponseDurationMsP50' => 120, 'originResponseDurationMsP95' => 480], 'avg' => ['sampleInterval' => 1]],
                ],
                'byCacheStatus' => [
                    ['count' => 900, 'avg' => ['sampleInterval' => 1], 'dimensions' => ['cacheStatus' => 'hit']],
                    ['count' => 600, 'avg' => ['sampleInterval' => 1], 'dimensions' => ['cacheStatus' => 'dynamic']],
                    ['count' => 300, 'avg' => ['sampleInterval' => 1], 'dimensions' => ['cacheStatus' => 'miss']],
                    ['count' => 200, 'avg' => ['sampleInterval' => 1], 'dimensions' => ['cacheStatus' => 'revalidated']],
                ],
                'byStatus' => [
                    ['count' => 1700, 'dimensions' => ['edgeResponseStatus' => 200]],
                    ['count' => 150, 'dimensions' => ['edgeResponseStatus' => 304]],
                    ['count' => 100, 'dimensions' => ['edgeResponseStatus' => 404]],
                    ['count' => 40, 'dimensions' => ['edgeResponseStatus' => 403]],
                    ['count' => 10, 'dimensions' => ['edgeResponseStatus' => 502]],
                ],
                'byContentType' => [['count' => 2000, 'dimensions' => ['edgeResponseContentTypeName' => 'html']]],
                'byCountry' => [
                    ['count' => 1500, 'dimensions' => ['clientCountryName' => 'FR']],
                    ['count' => 400, 'dimensions' => ['clientCountryName' => 'US']],
                    ['count' => 100, 'dimensions' => ['clientCountryName' => 'T1']],
                ],
                'byHost' => [
                    ['count' => 1800, 'dimensions' => ['clientRequestHTTPHost' => 'www.example.com']],
                    ['count' => 200, 'dimensions' => ['clientRequestHTTPHost' => 'api.example.com']],
                ],
                'byProtocol' => [['count' => 2000, 'dimensions' => ['clientRequestHTTPProtocol' => 'HTTP/2']]],
                'bySecurity' => [
                    ['count' => 1940, 'dimensions' => ['securityAction' => 'unknown', 'securitySource' => 'unknown']],
                    ['count' => 40, 'dimensions' => ['securityAction' => 'block', 'securitySource' => 'firewallCustom']],
                    ['count' => 15, 'dimensions' => ['securityAction' => 'managed_challenge', 'securitySource' => 'botFight']],
                    ['count' => 5, 'dimensions' => ['securityAction' => 'skip', 'securitySource' => 'firewallCustom']],
                ],
            ]]],
            'GraphQL Today' => ['zones' => [['today' => [[
                'dimensions' => ['date' => $variables['date'] ?? null],
                'sum' => ['requests' => 86_000, 'cachedRequests' => 43_000, 'bytes' => 5_368_709_120, 'cachedBytes' => 3_221_225_472, 'pageViews' => 12_000, 'threats' => 86],
                'uniq' => ['uniques' => 3_400],
            ]]]]],
            'GraphQL Rum' => ['accounts' => [[
                'pageloads' => [['count' => 9_500, 'sum' => ['visits' => 4_200]]],
                'byHost' => [
                    ['count' => 9_000, 'dimensions' => ['requestHost' => 'www.example.com']],
                    ['count' => 500, 'dimensions' => ['requestHost' => 'docs.example.com']],
                ],
                'timings' => [['count' => 9_000, 'quantiles' => ['pageLoadTimeP50' => 1_200_000, 'pageLoadTimeP75' => 2_100_000, 'dnsTimeP75' => 20_000, 'connectionTimeP75' => 45_000, 'requestTimeP75' => 180_000, 'responseTimeP75' => 30_000, 'pageRenderTimeP75' => 900_000]]],
                'vitals' => [['count' => 8_000, 'quantiles' => ['largestContentfulPaintP75' => 1_800_000, 'interactionToNextPaintP75' => 240_000, 'cumulativeLayoutShiftP75' => 0.31, 'timeToFirstByteP75' => 420_000, 'firstContentfulPaintP75' => 1_100_000]]],
            ]]],
            'GraphQL R2' => ['accounts' => [[
                'r2Storage' => [['dimensions' => ['bucketName' => 'assets', 'storageClass' => 'Standard', 'datetime' => $minute(30)], 'max' => ['payloadSize' => 9_126_805_504, 'metadataSize' => 1_048_576, 'objectCount' => 5_400, 'uploadCount' => 0]]],
                'r2Ops' => [
                    ['dimensions' => ['bucketName' => 'assets', 'actionType' => 'PutObject'], 'sum' => ['requests' => 12_000]],
                    ['dimensions' => ['bucketName' => 'assets', 'actionType' => 'GetObject'], 'sum' => ['requests' => 640_000]],
                    ['dimensions' => ['bucketName' => 'assets', 'actionType' => 'DeleteObject'], 'sum' => ['requests' => 300]],
                ],
                'r2Bandwidth' => [['dimensions' => ['bucketName' => 'assets'], 'sum' => ['bytesUpload' => 1_000_000, 'bytesDownload' => 50_000_000]]],
            ]]],
            'GraphQL Images' => ['accounts' => [[
                'imagesRequests' => [['sum' => ['requests' => $nothing ? 0 : 7_300]]],
                'imagesMonth' => [['date' => $variables['monthDate'] ?? null, 'transformations' => $nothing ? 0 : 4_200]],
            ]]],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
