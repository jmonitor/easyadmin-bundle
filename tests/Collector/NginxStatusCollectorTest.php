<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\NginxStatusCollector;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use PHPUnit\Framework\TestCase;

final class NginxStatusCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/nginx_status';

    public function testTheStatusPageIsRead(): void
    {
        $fetcher = new FixtureUrlFetcher(self::stubStatus());

        self::assertSame(Payloads::nginxStatus(), (new NginxStatusCollector(self::ENDPOINT, $fetcher))->collect());
        self::assertSame(self::ENDPOINT, $fetcher->url);
        self::assertSame(1, $fetcher->calls);
    }

    public function testAnUnreachablePageIsAnError(): void
    {
        try {
            (new NginxStatusCollector(self::ENDPOINT, new FixtureUrlFetcher(null)))->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(UnreachableServerException::class, $e, 'kept longer than a poll');
            self::assertSame('Cannot read the Nginx status at http://localhost/nginx_status. Enable stub_status in a location reachable from PHP and check "components.nginx.endpoint".', $e->getMessage());
            self::assertSame('Failed to fetch "http://localhost/nginx_status": Connection refused', $e->getPrevious()?->getMessage(), 'the root cause is chained');
        }
    }

    public function testAPageThatIsNotStubStatusIsAnError(): void
    {
        try {
            (new NginxStatusCollector(self::ENDPOINT, new FixtureUrlFetcher("<!DOCTYPE html>\n<title>Welcome</title>")))->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(UnreachableServerException::class, $e, 'the application would answer each poll otherwise');
            self::assertStringStartsWith('Cannot read the Nginx status at http://localhost/nginx_status.', $e->getMessage());
            self::assertSame('"http://localhost/nginx_status" is not a stub_status page.', $e->getPrevious()?->getMessage());
        }
    }

    public function testNameAndVersion(): void
    {
        $collector = new NginxStatusCollector(self::ENDPOINT, new FixtureUrlFetcher(null));

        self::assertSame('nginx.status', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public static function stubStatus(): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Nginx/stub_status.txt');
    }
}
