<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Snapshot;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Detection;
use Jmonitor\EasyAdminBundle\Snapshot\DeltaCalculator;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cache\RecordingCachePool;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection\StaticDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SnapshotProviderTest extends TestCase
{
    private RecordingCachePool $cache;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->cache = new RecordingCachePool();
        $this->clock = new MockClock('2026-10-12 10:00:00');
    }

    public function testCollectsOnceAndCachesEachCollectorWithItsOwnLifetime(): void
    {
        $status = new FakeCollector('mysql.status', ['Questions' => 100]);
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $provider = $this->provider(Component::Mysql, [
            new CollectorSpec($status, Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec($variables, Lifetime::Static),
        ]);

        $provider->get(Component::Mysql);
        $snapshot = $provider->get(Component::Mysql);

        self::assertSame(1, $status->collects);
        self::assertSame(1, $variables->collects);
        self::assertSame(['Questions' => 100], $snapshot->data('mysql.status'));
        self::assertSame(['version' => '8.4'], $snapshot->data('mysql.variables'));
        self::assertNull($snapshot->delta('mysql.status'), 'first sample: no previous one');
        self::assertSame(10, $this->cache->ttls[SnapshotProvider::cacheKey('mysql.status')], 'dynamic data lives for the polling interval');
        self::assertSame(3600, $this->cache->ttls[SnapshotProvider::cacheKey('mysql.variables')], 'static data lives for static_ttl');
    }

    public function testTheNextCollectionOfADynamicCollectorCarriesADelta(): void
    {
        $status = new FakeCollector('mysql.status', ['Questions' => 100]);
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $provider = $this->provider(Component::Mysql, [
            new CollectorSpec($status, Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec($variables, Lifetime::Static),
        ]);

        $provider->get(Component::Mysql);
        $this->cache->deleteItem(SnapshotProvider::cacheKey('mysql.status'));
        $status->setData(['Questions' => 250]);
        $this->clock->sleep(11);
        $snapshot = $provider->get(Component::Mysql);

        self::assertSame(2, $status->collects);
        self::assertSame(1, $variables->collects, 'still cached');
        self::assertSame(150, $snapshot->delta('mysql.status')?->getValue('Questions'));
        self::assertSame(11, $snapshot->delta('mysql.status')->elapsed);
    }

    public function testCollectorFailureIsCapturedAndCachedForTheDynamicLifetime(): void
    {
        $slow = new FakeCollector('mysql.slow_queries', collectFailure: new \RuntimeException('performance_schema table is not readable'));
        $provider = $this->provider(Component::Mysql, [new CollectorSpec($slow, Lifetime::Minute)]);

        $snapshot = $provider->get(Component::Mysql);
        $provider->get(Component::Mysql);

        self::assertFalse($snapshot->has('mysql.slow_queries'));
        self::assertSame('performance_schema table is not readable', $snapshot->result('mysql.slow_queries')?->error);
        self::assertSame(1, $slow->collects, 'the failure is cached too');
        self::assertSame(10, $this->cache->ttls[SnapshotProvider::cacheKey('mysql.slow_queries')], 'but only for the dynamic lifetime, not the collector one');
    }

    public function testRefreshForgetsTheComponent(): void
    {
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $provider = $this->provider(Component::Mysql, [new CollectorSpec($variables, Lifetime::Static)]);

        $provider->get(Component::Mysql);
        $provider->refresh(Component::Mysql);
        $provider->get(Component::Mysql);

        self::assertSame(2, $variables->collects);
    }

    public function testUnavailableComponentGivesAnEmptySnapshotWithTheReason(): void
    {
        $registry = new ComponentRegistry(
            ['mysql' => new StaticDetector(Component::Mysql, Detection::unavailable('Connection refused'))],
            ['mysql' => ['enabled' => true]],
        );
        $provider = new SnapshotProvider($registry, new CollectorRunner(), new DeltaCalculator($this->cache, 5), $this->cache, $this->clock, 10, 3600);

        $snapshot = $provider->get(Component::Mysql);

        self::assertSame([], $snapshot->results);
        self::assertSame('Connection refused', $snapshot->error);
    }

    /**
     * @param list<CollectorSpec> $specs
     */
    private function provider(Component $component, array $specs): SnapshotProvider
    {
        $registry = new ComponentRegistry(
            [$component->value => new StaticDetector($component, Detection::found(new CollectorSet($component, $specs)))],
            [$component->value => ['enabled' => 'auto']],
        );

        return new SnapshotProvider($registry, new CollectorRunner(), new DeltaCalculator($this->cache, 5), $this->cache, $this->clock, 10, 3600);
    }
}
