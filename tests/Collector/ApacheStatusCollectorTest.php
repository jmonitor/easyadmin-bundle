<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\ApacheStatusCollector;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
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
            self::assertInstanceOf(UnreachableServerException::class, $e, 'kept longer than a poll');
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
            self::assertInstanceOf(UnreachableServerException::class, $e, 'the application would answer each poll otherwise');
            self::assertStringStartsWith('Cannot read the Apache status at http://localhost/server-status.', $e->getMessage());
            self::assertStringContainsString('"http://localhost/server-status?auto" did not return a Scoreboard line', (string) $e->getPrevious()?->getMessage());
        }
    }

    public function testTheDurationsKeepTheirFractionOfAMillisecond(): void
    {
        $report = str_replace(['Total Duration: 2080000', 'DurationPerReq: 40'], ['Total Duration: 38168', 'DurationPerReq: .734'], self::report());

        $payload = (new ApacheStatusCollector(self::ENDPOINT, new FixtureUrlFetcher($report)))->collect();

        self::assertSame(38.168, $payload['total_duration'], 'in seconds');
        self::assertSame(0.734, $payload['duration_per_request'], 'in milliseconds');
    }

    public function testAReportWithoutDurationsHasNone(): void
    {
        $report = (string) preg_replace('/^(Total Duration|DurationPerReq): .*\n/m', '', self::report());

        $payload = (new ApacheStatusCollector(self::ENDPOINT, new FixtureUrlFetcher($report)))->collect();

        self::assertNull($payload['total_duration']);
        self::assertNull($payload['duration_per_request']);
    }

    public function testNameAndVersionDelegate(): void
    {
        $collector = new ApacheStatusCollector(self::ENDPOINT, new FixtureUrlFetcher(null));

        self::assertSame('apache', $collector->getName());
        self::assertSame(2, $collector->getVersion());
    }

    public static function report(): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Apache/server-status.txt');
    }
}
