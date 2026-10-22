<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Release;

use Jmonitor\EasyAdminBundle\Release\LatestRelease;
use Jmonitor\EasyAdminBundle\Release\Releases;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Logging\RecordingLogger;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Release\FixtureReleaseSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ReleasesTest extends TestCase
{
    private const KEY = 'jmonitor_easyadmin.release.jmonitor.easyadmin-bundle';

    public function testTheReleaseIsFetchedOnceThenCached(): void
    {
        $release = new LatestRelease('1.4.0', 'https://github.com/jmonitor/easyadmin-bundle/releases/tag/v1.4.0');
        $source = new FixtureReleaseSource($release);
        $releases = new Releases(new ArrayAdapter(), source: $source);

        self::assertEquals($release, $releases->latest('jmonitor/easyadmin-bundle'));
        self::assertEquals($release, $releases->latest('jmonitor/easyadmin-bundle'));
        self::assertSame(1, $source->calls);
    }

    public function testAnUnreadableReleaseGivesNothingAndIsNotFetchedAgain(): void
    {
        $logger = new RecordingLogger();
        $source = new FixtureReleaseSource(null);
        $releases = new Releases(new ArrayAdapter(), $logger, $source);

        self::assertNull($releases->latest('jmonitor/easyadmin-bundle'));
        self::assertNull($releases->latest('jmonitor/easyadmin-bundle'));
        self::assertSame(1, $source->calls);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::NOTICE, $logger->records[0][0]);
    }

    public function testAFailureExpiresSoonerThanASuccess(): void
    {
        $cache = new ArrayAdapter();
        (new Releases($cache, source: new FixtureReleaseSource(null), errorLifetime: 0))->latest('jmonitor/easyadmin-bundle');
        $source = new FixtureReleaseSource(new LatestRelease('1.4.0', 'u'));

        self::assertNotNull((new Releases($cache, source: $source))->latest('jmonitor/easyadmin-bundle'));
        self::assertSame(1, $source->calls);
    }

    public function testAnUnreachableGithubIsNotAskedForTheOtherPackages(): void
    {
        $source = new FixtureReleaseSource(null);
        $releases = new Releases(new ArrayAdapter(), source: $source);

        self::assertNull($releases->latest('jmonitor/easyadmin-bundle'));
        self::assertNull($releases->latest('jmonitor/collector'));
        self::assertSame(1, $source->calls);
    }

    public function testACachedUrlOutsideGithubIsFetchedAgain(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem(self::KEY)->set(['release' => ['version' => '1.4.0', 'url' => 'javascript:alert(1)']]));
        $source = new FixtureReleaseSource(new LatestRelease('1.4.0', 'https://github.com/jmonitor/easyadmin-bundle/releases/tag/v1.4.0'));

        self::assertSame('https://github.com/jmonitor/easyadmin-bundle/releases/tag/v1.4.0', (new Releases($cache, source: $source))->latest('jmonitor/easyadmin-bundle')?->url);
        self::assertSame(1, $source->calls);
    }

    public function testAFetchInProgressGivesNothing(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem(self::KEY)->set(['release' => null]));
        $source = new FixtureReleaseSource(new LatestRelease('1.4.0', 'u'));

        self::assertNull((new Releases($cache, source: $source))->latest('jmonitor/easyadmin-bundle'));
        self::assertSame(0, $source->calls);
    }

    public function testACachedValueOfUnknownShapeIsFetchedAgain(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem(self::KEY)->set('garbage'));
        $source = new FixtureReleaseSource(new LatestRelease('1.4.0', 'u'));

        self::assertEquals(new LatestRelease('1.4.0', 'u'), (new Releases($cache, source: $source))->latest('jmonitor/easyadmin-bundle'));
        self::assertSame(1, $source->calls);
    }
}
