<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\ApacheStatusCollector;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ApacheDetector;
use Jmonitor\EasyAdminBundle\Tests\Collector\ApacheStatusCollectorTest;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApacheDetectorTest extends TestCase
{
    public function testModPhpIsDetectedFromTheSapiWithoutReadingTheStatus(): void
    {
        $fetcher = new FixtureUrlFetcher(null);

        $detection = (new ApacheDetector('http://localhost/server-status', new RequestStack(), 'apache2handler', $fetcher))->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(Component::Apache, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id, 'implicit instance');
        $spec = $detection->instances[0]->collectors?->get('apache');
        self::assertNotNull($spec);
        self::assertInstanceOf(ApacheStatusCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
        self::assertSame(0, $fetcher->calls, 'detection never touches the network');
    }

    public function testPhpFpmBehindApacheIsDetectedFromTheServerSoftware(): void
    {
        $detection = (new ApacheDetector('http://localhost/server-status', self::requests('Apache/2.4.62 (Debian)'), 'fpm-fcgi'))->detect();

        self::assertTrue($detection->isFound());
    }

    public function testAnotherServerIsUnavailable(): void
    {
        $detection = (new ApacheDetector('http://localhost/server-status', self::requests('nginx/1.27.2'), 'fpm-fcgi'))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Apache is not serving this page (PHP SAPI "fpm-fcgi", server software "nginx/1.27.2").', $detection->error);
    }

    public function testNoRequestIsUnavailable(): void
    {
        $detection = (new ApacheDetector('http://localhost/server-status', new RequestStack(), 'cli'))->detect();

        self::assertSame('Apache is not serving this page (PHP SAPI "cli", server software "unknown").', $detection->error);
    }

    public function testTheCollectorReadsTheConfiguredEndpoint(): void
    {
        $fetcher = new FixtureUrlFetcher(ApacheStatusCollectorTest::report());
        $detection = (new ApacheDetector('http://apache:8080/status', new RequestStack(), 'apache2handler', $fetcher))->detect();

        $payload = $detection->instances[0]->collectors?->get('apache')?->collector->collect();

        self::assertSame('event', $payload['server_mpm'] ?? null);
        self::assertSame('http://apache:8080/status?auto', $fetcher->url);
    }

    private static function requests(string $software): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/admin/jmonitor/apache', server: ['SERVER_SOFTWARE' => $software]));

        return $requests;
    }
}
