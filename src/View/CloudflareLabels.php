<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\View;

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Symfony\Component\Intl\Countries;

/**
 * Display names and colours of the raw identifiers of the Cloudflare analytics. Cloudflare spells
 * the same security action differently from one dataset to the next (managed_challenge,
 * managedChallenge, managedchallenge).
 */
final class CloudflareLabels
{
    private const CACHED_STATUSES = ['hit', 'revalidated', 'stale', 'updating'];
    private const BLOCKS = ['block', 'connectionclose', 'forceconnectionclose'];
    private const CHALLENGES = ['challenge', 'jschallenge', 'managedchallenge', 'precursorinterstitialpageissued'];

    private const ACTIONS = [
        'block' => 'Block',
        'managedchallenge' => 'Managed challenge',
        'jschallenge' => 'JS challenge',
        'challenge' => 'Interactive challenge',
        'connectionclose' => 'Connection close',
        'forceconnectionclose' => 'Force connection close',
        'challengesolved' => 'Challenge solved',
        'challengebypassed' => 'Challenge bypassed',
        'jschallengesolved' => 'JS challenge solved',
        'jschallengebypassed' => 'JS challenge bypassed',
        'managedchallengenoninteractivesolved' => 'Managed challenge solved',
        'managedchallengeinteractivesolved' => 'Managed challenge solved (interactive)',
        'managedchallengebypassed' => 'Managed challenge bypassed',
    ];

    private const SOURCES = [
        'firewallcustom' => 'Custom rules',
        'firewallmanaged' => 'Managed rules',
        'firewallrules' => 'Firewall rules',
        'ratelimit' => 'Rate limiting',
        'botfight' => 'Bot fight mode',
        'botmanagement' => 'Bot management',
        'bic' => 'Browser integrity check',
        'hot' => 'Hotlink protection',
        'l7ddos' => 'HTTP DDoS protection',
        'securitylevel' => 'Security level',
        'zonelockdown' => 'Zone lockdown',
        'uablock' => 'User agent blocking',
        'waf' => 'WAF',
        'ip' => 'IP access rules',
        'iprange' => 'IP access rules (range)',
        'asn' => 'IP access rules (ASN)',
        'country' => 'IP access rules (country)',
        'validation' => 'Request validation',
        'apishield' => 'API Shield',
    ];

    public static function isBlock(string $action): bool
    {
        return in_array(self::normalize($action), self::BLOCKS, true);
    }

    public static function isChallenge(string $action): bool
    {
        return in_array(self::normalize($action), self::CHALLENGES, true);
    }

    public static function action(string $action): string
    {
        return self::ACTIONS[self::normalize($action)]
            ?? ucfirst(strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ', $action)));
    }

    public static function actionStyle(string $action): BadgeStyle
    {
        return match (true) {
            self::isBlock($action) => BadgeStyle::Danger,
            self::isChallenge($action) => BadgeStyle::Warning,
            default => BadgeStyle::Neutral,
        };
    }

    public static function source(string $source): string
    {
        return self::SOURCES[self::normalize($source)] ?? $source;
    }

    /**
     * Whether a cache status is the one of a response served from the cache.
     */
    public static function isCached(string $status): bool
    {
        return in_array($status, self::CACHED_STATUSES, true);
    }

    public static function cacheStatusStyle(string $status): ?BadgeStyle
    {
        return match (true) {
            self::isCached($status) => BadgeStyle::Success,
            in_array($status, ['miss', 'expired'], true) => BadgeStyle::Warning,
            $status === 'dynamic' => BadgeStyle::Info,
            default => null,
        };
    }

    public static function statusClassStyle(string $class): ?BadgeStyle
    {
        return match ($class) {
            '2xx' => BadgeStyle::Success,
            '3xx' => BadgeStyle::Info,
            '4xx' => BadgeStyle::Warning,
            '5xx' => BadgeStyle::Danger,
            default => null,
        };
    }

    /**
     * The flag and the English name of an ISO 3166-1 alpha-2 code, the code itself otherwise (T1 for Tor, XX).
     */
    public static function country(string $code): string
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || !Countries::exists($code)) {
            return $code;
        }

        $flag = implode('', array_map(static fn(string $letter): string => mb_chr(0x1F1E6 + ord($letter) - ord('A')), str_split($code)));

        return $flag . ' ' . Countries::getName($code, 'en');
    }

    private static function normalize(string $identifier): string
    {
        return strtolower(str_replace('_', '', $identifier));
    }
}
