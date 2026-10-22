<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Release\LatestRelease;
use Jmonitor\EasyAdminBundle\View\PackageVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackageVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?LatestRelease, ?Badge, ?string}>
     */
    public static function versions(): iterable
    {
        $latest = new LatestRelease('1.4.0', 'u');

        yield 'the latest' => ['1.4.0', $latest, new Badge(BadgeStyle::Success, 'Up to date'), null];
        yield 'leading v' => ['v1.4.0', $latest, new Badge(BadgeStyle::Success, 'Up to date'), null];
        yield 'newer than the latest' => ['1.5.0', $latest, new Badge(BadgeStyle::Success, 'Up to date'), null];
        yield 'older' => ['1.3.2', $latest, new Badge(BadgeStyle::Warning, '1.4.0 available'), 'u'];
        yield 'development branch' => ['1.x-dev', $latest, null, null];
        yield 'dev-main' => ['dev-main', $latest, null, null];
        yield 'pre-release' => ['1.5.0-beta1', $latest, null, null];
        yield 'not installed' => [null, $latest, null, null];
        yield 'latest unknown' => ['1.4.0', null, null, null];
    }

    #[DataProvider('versions')]
    public function testTheBadge(?string $installed, ?LatestRelease $latest, ?Badge $badge, ?string $url): void
    {
        $version = PackageVersion::of('jmonitor/easyadmin-bundle', $installed, $latest);

        self::assertSame('jmonitor/easyadmin-bundle', $version->package);
        self::assertSame($installed, $version->installed);
        self::assertEquals($badge, $version->badge);
        self::assertSame($url, $version->url);
    }
}
