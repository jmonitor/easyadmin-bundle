<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Metric\Badge;
use Jmonitor\EasyAdminBundle\Metric\BadgeStyle;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Payloads;
use Jmonitor\EasyAdminBundle\View\SymfonyView;
use PHPUnit\Framework\TestCase;

final class SymfonyViewTest extends TestCase
{
    public function testFactsComponentsAndRecommendations(): void
    {
        $view = new SymfonyView($this->symfony(Payloads::symfony()), $this->php([
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 120,
            'opcache' => ['config' => ['directives' => ['opcache.enable' => true]]],
            'apcu' => ['config' => ['apc.enabled' => false], 'cache_info' => [], 'sma_info' => []],
        ]));

        self::assertSame('7.4.2', $view->version);
        self::assertSame('PROD', $view->environment);
        self::assertSame('Debug: disabled', $view->debug?->label);
        self::assertSame(['TwigBundle', 'FrameworkBundle', 'EasyAdminBundle'], $view->bundles);

        self::assertTrue($view->recommendationsAvailable);
        self::assertSame('Enabled', $view->opcache->label);
        self::assertSame(BadgeStyle::Warning, $view->apcu->style);
        self::assertSame(4_194_304, $view->realpathCacheSize);
        self::assertSame(BadgeStyle::Success, $view->realpathCacheSizeStyle);
        self::assertSame(BadgeStyle::Warning, $view->realpathCacheTtl?->style);
        self::assertSame('120', $view->realpathCacheTtl->label);

        self::assertTrue($view->messengerAvailable);
        self::assertSame('3', self::badge($view->messengerTransports['async'])->label);
        self::assertSame(BadgeStyle::Info, self::badge($view->messengerTransports['async'])->style);
        self::assertSame(BadgeStyle::Danger, self::badge($view->messengerTransports['failed'])->style);
        self::assertSame(0, $view->messengerTransports['retry']);
        self::assertSame(['sync'], $view->messengerUncountable);

        self::assertTrue($view->schedulerAvailable);
        self::assertSame('app:ping', $view->schedulerTasks[0]['command'], 'sorted by next run');
        self::assertSame('every 5 minutes', $view->nextTask['trigger'] ?? null);
        self::assertSame('0-10 second', $view->nextTask['jitter'] ?? null);
        self::assertSame(1_760_000_300, $view->nextTask['next_run'] ?? null);
        self::assertNull($view->schedulerTasks[1]['jitter']);

        self::assertTrue($view->flexAvailable);
        self::assertFalse($view->recipesUpToDate);
        self::assertSame(['symfony/framework-bundle', 'symfony/twig-bundle'], $view->outdatedRecipes);
        self::assertSame('Outdated', $view->flex?->label);
    }

    public function testAFlexCheckThatCouldNotRunIsNotReportedAsOutdated(): void
    {
        $view = new SymfonyView($this->symfony(['env' => 'prod', 'debug' => false, 'bundles' => [], 'components' => [
            'flex_recipes' => ['up_to_date' => false, 'outdated_recipes' => []],
        ]]), $this->php([]));

        self::assertTrue($view->flexAvailable);
        self::assertTrue($view->flexCheckFailed, 'composer is missing on the server: no verdict, not "0 outdated"');
        self::assertNull($view->flex);
    }

    public function testUpToDateRecipesAndDebug(): void
    {
        $view = new SymfonyView($this->symfony(['env' => 'dev', 'debug' => true, 'bundles' => [], 'components' => [
            'flex_recipes' => ['up_to_date' => true, 'outdated_recipes' => []],
        ]]), $this->php([]));

        self::assertSame('Debug: enabled', $view->debug?->label);
        self::assertSame('Up to date', $view->flex?->label);
        self::assertFalse($view->messengerAvailable);
        self::assertFalse($view->schedulerAvailable);
        self::assertNull($view->nextTask);
    }

    public function testOpcacheTurnedOffAtRuntimeIsRecommended(): void
    {
        $view = new SymfonyView($this->symfony(Payloads::symfony()), $this->php([
            'opcache' => ['config' => ['directives' => ['opcache.enable' => true]], 'status' => ['opcache_enabled' => false]],
        ]));

        self::assertSame('Disabled', $view->opcache->label);
    }

    public function testWithoutPhpMetricsThereAreNoRecommendations(): void
    {
        $view = new SymfonyView($this->symfony(['env' => 'dev', 'debug' => true, 'bundles' => [], 'components' => []]), new ComponentSnapshot(Component::Php, [], 'disabled'));

        self::assertFalse($view->recommendationsAvailable);
        self::assertNull($view->realpathCacheSize);
        self::assertNull($view->realpathCacheTtl);
    }

    private static function badge(mixed $value): Badge
    {
        self::assertInstanceOf(Badge::class, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function symfony(array $data): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Symfony, ['symfony' => new CollectorResult('symfony', $data, null, 1_000)]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function php(array $data): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', $data, null, 1_000)]);
    }
}
