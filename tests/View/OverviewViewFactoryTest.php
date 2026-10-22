<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Availability;
use Jmonitor\EasyAdminBundle\Detection\ComponentDetectorInterface;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Detection;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Detection\SkippedConnectionReporter;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Release\LatestRelease;
use Jmonitor\EasyAdminBundle\Release\Releases;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\StaticDetector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Logging\RecordingLogger;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Release\FixtureReleaseSource;
use Jmonitor\EasyAdminBundle\View\OverviewComponent;
use Jmonitor\EasyAdminBundle\View\OverviewInstance;
use Jmonitor\EasyAdminBundle\View\OverviewView;
use Jmonitor\EasyAdminBundle\View\OverviewViewFactory;
use Jmonitor\EasyAdminBundle\WebNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class OverviewViewFactoryTest extends TestCase
{
    private SkippedConnectionReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new SkippedConnectionReporter(new ArrayAdapter(), new RecordingLogger(), 3600);
    }

    public function testTheAvailableComponentsComeFirstEachGroupInTheOrderOfTheTabs(): void
    {
        $view = $this->overview(
            [
                'php' => $this->found(Component::Php),
                'nginx' => new StaticDetector(Component::Nginx, Detection::unavailable('Nginx is not serving this page.')),
                'mysql' => new StaticDetector(Component::Mysql, Detection::unavailable('Connection refused')),
                'redis' => $this->found(Component::Redis),
            ],
            ['mysql' => ['enabled' => true], 'symfony' => ['enabled' => false]],
        );

        self::assertSame(
            [Component::Php, Component::Mysql, Component::Redis, Component::System, Component::FrankenPhp, Component::Apache, Component::Nginx, Component::Symfony, Component::Postgresql],
            array_map(static fn(OverviewComponent $component): Component => $component->component, $view->components),
        );
    }

    public function testAComponentNotMonitoredGivesItsReason(): void
    {
        $view = $this->overview(
            ['apache' => new StaticDetector(Component::Apache, Detection::unavailable('Apache is not serving this page.'))],
            ['postgresql' => ['enabled' => false]],
        );

        $apache = $this->component($view, Component::Apache);
        self::assertSame(Availability::NotDetected, $apache->availability);
        self::assertSame('Apache is not serving this page.', $apache->detail);
        self::assertFalse($apache->failing);

        $postgresql = $this->component($view, Component::Postgresql);
        self::assertSame(Availability::Disabled, $postgresql->availability);
        self::assertSame('Disabled in the configuration.', $postgresql->detail);
    }

    public function testAForcedComponentWithoutInstanceIsFailing(): void
    {
        $view = $this->overview(
            ['redis' => new StaticDetector(Component::Redis, Detection::unavailable('No Redis DSN found.'))],
            ['redis' => ['enabled' => true]],
        );

        $redis = $this->component($view, Component::Redis);
        self::assertSame(Availability::Available, $redis->availability);
        self::assertTrue($redis->failing);
        self::assertSame('No Redis DSN found.', $redis->detail);
        self::assertSame([], $redis->instances);
    }

    public function testTheStyleOfARowFollowsItsState(): void
    {
        $view = $this->overview(
            ['php' => $this->found(Component::Php), 'redis' => new StaticDetector(Component::Redis, Detection::unavailable('No Redis DSN found.'))],
            ['redis' => ['enabled' => true], 'postgresql' => ['enabled' => false]],
        );

        self::assertTrue($this->component($view, Component::Php)->isAvailable());
        self::assertSame(BadgeStyle::Success, $this->component($view, Component::Php)->style());
        self::assertTrue($this->component($view, Component::Redis)->isAvailable());
        self::assertSame(BadgeStyle::Danger, $this->component($view, Component::Redis)->style());
        self::assertFalse($this->component($view, Component::Postgresql)->isAvailable());
        self::assertSame(BadgeStyle::Neutral, $this->component($view, Component::Postgresql)->style());
    }

    public function testThePhpRowShowsItsVersionAndSapi(): void
    {
        $view = $this->overview(['php' => $this->found(Component::Php)]);

        $php = $this->component($view, Component::Php);
        self::assertSame(Availability::Available, $php->availability);
        self::assertFalse($php->failing);
        self::assertSame(PHP_VERSION . ' · ' . PHP_SAPI, $php->detail);
    }

    public function testNginxShowsTheServerSoftware(): void
    {
        $view = $this->overview(['nginx' => $this->found(Component::Nginx)], request: new Request(server: ['SERVER_SOFTWARE' => 'nginx/1.27.4']));

        self::assertSame('nginx/1.27.4', $this->component($view, Component::Nginx)->detail);
    }

    public function testInstancesInErrorComeFirstAndTheRestIsCounted(): void
    {
        $instances = [];

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $id) {
            $instances[] = in_array($id, ['c', 'f'], true)
                ? Instance::unavailable(Component::Mysql, 'Unknown database.', $id)
                : Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, []), $id, 'db_' . $id);
        }

        $view = $this->overview(['mysql' => new StaticDetector(Component::Mysql, Detection::instances($instances))]);

        $mysql = $this->component($view, Component::Mysql);
        self::assertSame(['c', 'f', 'a', 'b', 'd'], array_map(static fn(OverviewInstance $instance): string => $instance->label, $mysql->instances));
        self::assertEquals(new OverviewInstance('c', 'Unknown database.', true), $mysql->instances[0]);
        self::assertEquals(new OverviewInstance('a', 'db_a', false), $mysql->instances[2]);
        self::assertSame(2, $mysql->more);
        self::assertFalse($mysql->failing);
        self::assertNull($mysql->detail);
    }

    public function testEveryInstanceInErrorMakesTheComponentFailing(): void
    {
        $view = $this->overview(['redis' => new StaticDetector(Component::Redis, Detection::instances([
            Instance::unavailable(Component::Redis, 'Empty Redis DSN.', 'cache'),
            Instance::unavailable(Component::Redis, 'Unknown Redis service "x".', 'sessions'),
        ]))]);

        $redis = $this->component($view, Component::Redis);
        self::assertTrue($redis->failing);
        self::assertCount(2, $redis->instances);
        self::assertSame(0, $redis->more);
    }

    public function testTheVersionsCarryTheLatestRelease(): void
    {
        $view = $this->overview([], latest: new LatestRelease('1.4.0', 'u'));

        self::assertCount(2, $view->versions);
        self::assertSame('jmonitor/easyadmin-bundle', $view->versions[0]->package);
        self::assertSame('jmonitor/collector', $view->versions[1]->package);
        self::assertSame('1.3.0', $view->versions[0]->installed);
        self::assertSame('1.4.0 available', $view->versions[0]->badge?->label);
        self::assertSame('u', $view->versions[0]->url);
    }

    public function testTheSkippedConnectionsAreReadAfterTheDetection(): void
    {
        $reporter = $this->reporter;
        $mysql = new class ($reporter) implements ComponentDetectorInterface {
            public function __construct(
                private readonly SkippedConnectionReporter $reporter,
            ) {}

            public function component(): Component
            {
                return Component::Mysql;
            }

            public function detect(): Detection
            {
                $this->reporter->report('legacy', 'refused');

                return Detection::unavailable('No MySQL or MariaDB Doctrine connection found.');
            }
        };

        self::assertSame(['legacy' => 'refused'], $this->overview(['mysql' => $mysql])->skippedConnections);
    }

    public function testTheConfigurationIsCarried(): void
    {
        $view = $this->overview([]);

        self::assertSame(0, $view->pollingInterval);
        self::assertSame(3600, $view->staticTtl);
        self::assertSame('cache.app', $view->cachePool);
        self::assertSame('ROLE_ADMIN', $view->permission);
    }

    public function testItTellsWhichWebServerAnswered(): void
    {
        self::assertSame('web-1', $this->overview([], node: 'web-1')->node);
        self::assertNull($this->overview([], node: '')->node, 'a server without name');
    }

    /**
     * @param array<string, ComponentDetectorInterface>     $detectors
     * @param array<string, array{enabled: 'auto'|bool}> $overrides
     */
    private function overview(array $detectors, array $overrides = [], ?LatestRelease $latest = null, ?Request $request = null, string $node = 'web-1'): OverviewView
    {
        $config = [];

        foreach (Component::cases() as $component) {
            $config[$component->value] = ['enabled' => 'auto'];
        }

        $requestStack = new RequestStack();
        $requestStack->push($request ?? new Request());

        $factory = new OverviewViewFactory(
            new ComponentRegistry($detectors, array_replace($config, $overrides)),
            $this->reporter,
            new Releases(new ArrayAdapter(), source: new FixtureReleaseSource($latest)),
            $requestStack,
            0,
            3600,
            'cache.app',
            'ROLE_ADMIN',
            static fn(string $package): string => '1.3.0',
            new WebNode($node),
        );

        return $factory->create();
    }

    private function found(Component $component): StaticDetector
    {
        return new StaticDetector($component, Detection::found(new CollectorSet($component, [])));
    }

    private function component(OverviewView $view, Component $component): OverviewComponent
    {
        foreach ($view->components as $row) {
            if ($row->component === $component) {
                return $row;
            }
        }

        self::fail(sprintf('No row for %s.', $component->value));
    }
}
