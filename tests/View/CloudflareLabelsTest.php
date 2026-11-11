<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\View\CloudflareLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudflareLabelsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, BadgeStyle}>
     */
    public static function actions(): iterable
    {
        yield 'block' => ['block', 'Block', BadgeStyle::Danger];
        yield 'connection close' => ['connection_close', 'Connection close', BadgeStyle::Danger];
        yield 'snake case' => ['managed_challenge', 'Managed challenge', BadgeStyle::Warning];
        yield 'camel case' => ['managedChallenge', 'Managed challenge', BadgeStyle::Warning];
        yield 'js challenge' => ['jschallenge', 'JS challenge', BadgeStyle::Warning];
        yield 'a solved challenge let the request through' => ['challenge_solved', 'Challenge solved', BadgeStyle::Neutral];
        yield 'skip' => ['skip', 'Skip', BadgeStyle::Neutral];
        yield 'unknown to the bundle' => ['setCacheSettings', 'Set cache settings', BadgeStyle::Neutral];
    }

    #[DataProvider('actions')]
    public function testASecurityActionHasANameAndAColour(string $action, string $label, BadgeStyle $style): void
    {
        self::assertSame($label, CloudflareLabels::action($action));
        self::assertSame($style, CloudflareLabels::actionStyle($action));
    }

    public function testASecuritySourceIsNamedOrKeptAsIs(): void
    {
        self::assertSame('Custom rules', CloudflareLabels::source('firewallCustom'));
        self::assertSame('Rate limiting', CloudflareLabels::source('ratelimit'));
        self::assertSame('somethingNew', CloudflareLabels::source('somethingNew'));
    }

    public function testACountryIsItsFlagAndName(): void
    {
        self::assertSame('🇫🇷 France', CloudflareLabels::country('FR'));
        self::assertSame('T1', CloudflareLabels::country('T1'), 'Tor');
        self::assertSame('XX', CloudflareLabels::country('XX'), 'unknown');
        self::assertSame('', CloudflareLabels::country(''));
    }

    public function testCacheStatusesAreColouredByWhatReachedTheOrigin(): void
    {
        self::assertSame(BadgeStyle::Success, CloudflareLabels::cacheStatusStyle('hit'));
        self::assertSame(BadgeStyle::Success, CloudflareLabels::cacheStatusStyle('stale'));
        self::assertSame(BadgeStyle::Warning, CloudflareLabels::cacheStatusStyle('miss'));
        self::assertSame(BadgeStyle::Info, CloudflareLabels::cacheStatusStyle('dynamic'));
        self::assertNull(CloudflareLabels::cacheStatusStyle('none'));
        self::assertNull(CloudflareLabels::cacheStatusStyle('other'));
    }

    public function testStatusClassesAreColoured(): void
    {
        self::assertSame(BadgeStyle::Success, CloudflareLabels::statusClassStyle('2xx'));
        self::assertSame(BadgeStyle::Danger, CloudflareLabels::statusClassStyle('5xx'));
        self::assertNull(CloudflareLabels::statusClassStyle('other'));
    }
}
