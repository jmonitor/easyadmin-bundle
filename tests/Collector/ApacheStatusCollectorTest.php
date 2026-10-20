<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\ApacheStatusCollector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use PHPUnit\Framework\TestCase;

final class ApacheStatusCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/server-status';

    public function testTheMachineReadableReportIsRead(): void
    {
        $fetcher = new FixtureUrlFetcher(self::report());

        self::assertSame(Payloads::apache(), (new ApacheStatusCollector(self::ENDPOINT, $fetcher))->collect());
        self::assertSame('http://localhost/server-status?auto', $fetcher->url);
    }

    public function testAnUnreachablePageIsAnError(): void
    {
        try {
            (new ApacheStatusCollector(self::ENDPOINT, new FixtureUrlFetcher(null)))->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Cannot read the Apache status at http://localhost/server-status. Enable mod_status with a "SetHandler server-status" location reachable from PHP and check "components.apache.endpoint".', $e->getMessage());
            self::assertSame('Failed to fetch "http://localhost/server-status?auto": Connection refused', $e->getPrevious()?->getMessage(), 'the root cause is chained for SnapshotProvider::describe()');
        }
    }

    public function testAPageThatIsNotModStatusIsAnError(): void
    {
        try {
            (new ApacheStatusCollector(self::ENDPOINT, new FixtureUrlFetcher("<!DOCTYPE html>\n<title>Welcome</title>")))->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Cannot read the Apache status at http://localhost/server-status.', $e->getMessage());
            self::assertSame('"http://localhost/server-status?auto" is not a mod_status report.', $e->getPrevious()?->getMessage());
        }
    }

    public function testNameAndVersionDelegate(): void
    {
        $collector = new ApacheStatusCollector(self::ENDPOINT, new FixtureUrlFetcher(null));

        self::assertSame('apache', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public static function report(): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Apache/server-status.txt');
    }
}
