<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\EasyAdminBundle\Collector\CachedShellExecutor;
use Jmonitor\EasyAdminBundle\Collector\NginxStatusCollector;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureShellExecutor;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class NginxStatusCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/nginx_status';

    public function testTheStatusPageAndTheCommandsAreRead(): void
    {
        $fetcher = new FixtureUrlFetcher(self::stubStatus());

        $payload = self::collector($fetcher, new FixtureShellExecutor(self::commands()))->collect();

        self::assertSame(array_replace(Payloads::nginx(), ['cpu_count' => PHP_OS_FAMILY === 'Linux' ? 4 : null]), $payload, 'NginxCollector runs "nproc" on Linux only');
        self::assertSame(self::ENDPOINT, $fetcher->url);
    }

    public function testAnUnreachablePageIsAnError(): void
    {
        try {
            self::collector(new FixtureUrlFetcher(null), new FixtureShellExecutor(null))->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(UnreachableServerException::class, $e, 'kept longer than a poll');
            self::assertSame('Cannot read the Nginx status at http://localhost/nginx_status. Enable stub_status in a location reachable from PHP and check "components.nginx.endpoint".', $e->getMessage());
            self::assertSame('Failed to fetch "http://localhost/nginx_status": Connection refused', $e->getPrevious()?->getMessage(), 'the root cause is chained for SnapshotProvider::describe()');
        }
    }

    public function testAPageThatIsNotStubStatusIsAnError(): void
    {
        try {
            self::collector(new FixtureUrlFetcher("<!DOCTYPE html>\n<title>Welcome</title>"), new FixtureShellExecutor(null))->collect();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(UnreachableServerException::class, $e, 'the application would answer each poll otherwise');
            self::assertStringStartsWith('Cannot read the Nginx status at http://localhost/nginx_status.', $e->getMessage());
            self::assertSame('"http://localhost/nginx_status" is not a stub_status page.', $e->getPrevious()?->getMessage());
        }
    }

    public function testWithoutNginxVTheVersionIsTheOnePassedToPhpFpm(): void
    {
        $payload = self::collector(new FixtureUrlFetcher(self::stubStatus()), new FixtureShellExecutor(null), '1.27.2')->collect();

        self::assertSame('1.27.2', $payload['version']);
        self::assertNull($payload['config']['config_path']);
    }

    public function testNginxVWinsOverTheVersionPassedToPhpFpm(): void
    {
        $payload = self::collector(new FixtureUrlFetcher(self::stubStatus()), new FixtureShellExecutor(self::commands()), '1.27.2')->collect();

        self::assertSame('1.26.2', $payload['version']);
    }

    public function testTheCommandsRunOncePerTtlUntilARefresh(): void
    {
        $cache = new ArrayAdapter();
        $shell = new FixtureShellExecutor(self::commands());

        self::collector(new FixtureUrlFetcher(self::stubStatus()), $shell, null, $cache)->collect();
        $calls = $shell->calls;
        self::collector(new FixtureUrlFetcher(self::stubStatus()), $shell, null, $cache)->collect();
        self::assertSame($calls, $shell->calls, 'a new request reads the cached outputs');

        $collector = self::collector(new FixtureUrlFetcher(self::stubStatus()), $shell, null, $cache);
        $collector->refresh();
        $collector->collect();
        self::assertSame(2 * $calls, $shell->calls);
    }

    public function testNameAndVersionDelegate(): void
    {
        $collector = self::collector(new FixtureUrlFetcher(null), new FixtureShellExecutor(null));

        self::assertSame('nginx', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public static function stubStatus(): string
    {
        return self::fixture('stub_status.txt');
    }

    /**
     * @return array<string, string>
     */
    public static function commands(): array
    {
        return ['nginx -V' => self::fixture('nginx-V.txt'), 'nginx -T' => self::fixture('nginx-T.txt'), 'nproc --all' => "4\n"];
    }

    private static function collector(FixtureUrlFetcher $fetcher, FixtureShellExecutor $shell, ?string $fallbackVersion = null, ?ArrayAdapter $cache = null): NginxStatusCollector
    {
        $cached = new CachedShellExecutor($cache ?? new ArrayAdapter(), 3600, 'nginx', inner: $shell);

        return new NginxStatusCollector(new NginxCollector(self::ENDPOINT, $cached, $fetcher), self::ENDPOINT, $cached, $fallbackVersion);
    }

    private static function fixture(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Nginx/' . $file);
    }
}
