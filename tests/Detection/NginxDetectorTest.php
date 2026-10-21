<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\NginxStatusCollector;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\NginxDetector;
use Jmonitor\EasyAdminBundle\Tests\Collector\NginxStatusCollectorTest;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class NginxDetectorTest extends TestCase
{
    public function testPhpFpmBehindNginxIsDetectedFromTheServerSoftwareWithoutReadingAnything(): void
    {
        $fetcher = new FixtureUrlFetcher(null);
        $shell = new FixtureShellExecutor(null);

        $detection = self::detector(self::requests('nginx/1.27.2'), $fetcher, $shell)->detect();

        self::assertTrue($detection->isFound());
        self::assertSame(Component::Nginx, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id, 'implicit instance');
        $spec = $detection->instances[0]->collectors?->get('nginx');
        self::assertNotNull($spec);
        self::assertInstanceOf(NginxStatusCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertTrue($spec->trackDelta);
        self::assertSame(0, $fetcher->calls, 'detection never touches the network');
        self::assertSame(0, $shell->calls, 'nor runs a command');
    }

    public function testTheCollectorReadsTheConfiguredEndpointAndFallsBackToTheServerSoftwareVersion(): void
    {
        $fetcher = new FixtureUrlFetcher(NginxStatusCollectorTest::stubStatus());
        $detection = self::detector(self::requests('nginx/1.27.2'), $fetcher, new FixtureShellExecutor(null), 'http://nginx:8080/status')->detect();

        $payload = $detection->instances[0]->collectors?->get('nginx')?->collector->collect();

        self::assertSame('1.27.2', $payload['version'] ?? null);
        self::assertSame('http://nginx:8080/status', $fetcher->url);
    }

    public function testAServerSoftwareWithoutVersionIsNginxToo(): void
    {
        $detection = self::detector(self::requests('nginx'))->detect();

        self::assertTrue($detection->isFound());
    }

    public function testAnotherServerIsUnavailable(): void
    {
        $detection = self::detector(self::requests('Apache/2.4.62 (Debian)'))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Nginx is not serving this page (server software "Apache/2.4.62 (Debian)").', $detection->error);
    }

    public function testAServerNamedAfterNginxIsNotNginx(): void
    {
        self::assertFalse(self::detector(self::requests('nginxish/1.0'))->detect()->isFound());
    }

    public function testNoRequestIsUnavailable(): void
    {
        $detection = self::detector(new RequestStack())->detect();

        self::assertSame('Nginx is not serving this page (server software "unknown").', $detection->error);
    }

    private static function detector(RequestStack $requests, ?FixtureUrlFetcher $fetcher = null, ?FixtureShellExecutor $shell = null, string $endpoint = 'http://localhost/nginx_status'): NginxDetector
    {
        return new NginxDetector($endpoint, $requests, new ArrayAdapter(), 3600, new NullLogger(), $fetcher ?? new FixtureUrlFetcher(null), $shell ?? new FixtureShellExecutor(null));
    }

    private static function requests(string $software): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/admin/jmonitor/nginx', server: ['SERVER_SOFTWARE' => $software]));

        return $requests;
    }
}
