<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\View;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\View\Recommendation;
use Jmonitor\EasyAdminBundle\View\SymfonyView;
use PHPUnit\Framework\TestCase;

final class SymfonyViewTest extends TestCase
{
    public function testFactsComponentsAndRecommendations(): void
    {
        $view = new SymfonyView($this->symfony([
            'env' => 'prod',
            'debug' => false,
            'version' => '7.4.2',
            'bundles' => ['TwigBundle', 'FrameworkBundle', 'EasyAdminBundle'],
            'project_dir' => '/srv/app',
            'cache_dir' => '/srv/app/var/cache/prod',
            'log_dir' => '/srv/app/var/log',
            'charset' => 'UTF-8',
            'components' => [
                'scheduler' => [
                    ['trigger' => 'every 1 hour', 'command' => 'app:cleanup', 'next_run' => 1_760_003_600, 'description' => 'Clean up'],
                    ['trigger' => 'every 5 minutes with 0-10 second jitter', 'command' => 'app:ping', 'next_run' => 1_760_000_300, 'description' => 'Ping'],
                ],
                'flex_recipes' => ['up_to_date' => false, 'outdated_recipes' => ['symfony/framework-bundle', 'symfony/twig-bundle']],
                'messenger' => ['transports' => ['async' => ['count' => 3], 'failed' => ['count' => 0]], 'uncountable_transports' => ['sync']],
            ],
        ]), $this->php([
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 120,
            'opcache' => ['status' => ['opcache_enabled' => true], 'config' => ['directives' => ['opcache.enable' => true]]],
            'apcu' => ['config' => ['apc.enabled' => false], 'cache_info' => [], 'sma_info' => []],
        ]));

        self::assertTrue($view->available);
        self::assertSame('7.4.2', $view->version);
        self::assertSame('prod', $view->environment);
        self::assertFalse($view->debug);
        self::assertFalse($view->debugInProduction);
        self::assertSame(['EasyAdminBundle', 'FrameworkBundle', 'TwigBundle'], $view->bundles);
        self::assertSame('/srv/app', $view->projectDir);

        self::assertTrue($view->messengerAvailable);
        self::assertSame(['async' => 3, 'failed' => 0], $view->messengerTransports);
        self::assertSame(['sync'], $view->messengerUncountable);

        self::assertTrue($view->schedulerAvailable);
        self::assertSame('app:ping', $view->schedulerTasks[0]['command'], 'sorted by next run');
        self::assertSame('app:ping', $view->nextTask['command'] ?? null);
        self::assertSame(1_760_000_300, $view->nextTask['next_run']);

        self::assertTrue($view->flexAvailable);
        self::assertFalse($view->recipesUpToDate);
        self::assertSame(['symfony/framework-bundle', 'symfony/twig-bundle'], $view->outdatedRecipes);

        self::assertEquals([
            new Recommendation('OPcache enabled', true),
            new Recommendation('APCu enabled', false),
            new Recommendation('realpath_cache_size >= 4M', true, '4096K'),
            new Recommendation('realpath_cache_ttl >= 600', false, '120'),
        ], $view->recommendations);
    }

    public function testDebugInProductionIsFlagged(): void
    {
        $view = new SymfonyView($this->symfony(['env' => 'prod', 'debug' => true, 'bundles' => [], 'components' => []]), $this->php([]));

        self::assertTrue($view->debugInProduction);
        self::assertFalse($view->messengerAvailable);
        self::assertFalse($view->schedulerAvailable);
        self::assertFalse($view->flexAvailable);
        self::assertNull($view->nextTask);
    }

    public function testUnknownPhpFactsGiveUndecidedRecommendations(): void
    {
        $view = new SymfonyView($this->symfony(['env' => 'dev', 'debug' => true, 'bundles' => [], 'components' => []]), new ComponentSnapshot(Component::Php, [], 'disabled'));

        foreach ($view->recommendations as $recommendation) {
            self::assertNull($recommendation->ok, $recommendation->label);
        }
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
