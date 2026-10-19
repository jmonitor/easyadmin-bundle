<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Snapshot;

use Jmonitor\EasyAdminBundle\Collector\CollectorRunner;
use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\RefreshableCollectorInterface;
use Jmonitor\EasyAdminBundle\Collector\UnreachableServerException;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Snapshot\DeltaCalculator;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cache\RecordingCachePool;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Collector\FakeCollector;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
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
        [$provider, $instance] = $this->provider(Component::Mysql, [
            new CollectorSpec($status, Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec($variables, Lifetime::Static),
        ]);

        $provider->get($instance);
        $snapshot = $provider->get($instance);

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
        [$provider, $instance] = $this->provider(Component::Mysql, [
            new CollectorSpec($status, Lifetime::Dynamic, trackDelta: true),
            new CollectorSpec($variables, Lifetime::Static),
        ]);

        $provider->get($instance);
        $this->cache->deleteItem(SnapshotProvider::cacheKey('mysql.status'));
        $status->setData(['Questions' => 250]);
        $this->clock->sleep(11);
        $snapshot = $provider->get($instance);

        self::assertSame(2, $status->collects);
        self::assertSame(1, $variables->collects, 'still cached');
        self::assertSame(150, $snapshot->delta('mysql.status')?->getValue('Questions'));
        self::assertSame(11, $snapshot->delta('mysql.status')->elapsed);
    }

    public function testARefreshRightAfterACollectKeepsItsRates(): void
    {
        $status = new FakeCollector('mysql.status', ['Questions' => 100]);
        [$provider, $instance] = $this->provider(Component::Mysql, [new CollectorSpec($status, Lifetime::Dynamic, trackDelta: true)]);

        $provider->get($instance);
        $this->clock->sleep(2);
        $status->setData(['Questions' => 120]);
        $provider->refresh($instance);
        $refreshed = $provider->get($instance);

        self::assertSame(10.0, $refreshed->delta('mysql.status')?->getPerSec('Questions'));

        $this->cache->deleteItem(SnapshotProvider::cacheKey('mysql.status'));
        $this->clock->sleep(9);
        $status->setData(['Questions' => 210]);

        self::assertSame(11, $provider->get($instance)->delta('mysql.status')?->elapsed, 'the next poll spans a full interval');
    }

    public function testCollectorFailureIsCapturedAndCachedForTheDynamicLifetime(): void
    {
        $slow = new FakeCollector('mysql.slow_queries', collectFailure: new \RuntimeException('performance_schema table is not readable'));
        [$provider, $instance] = $this->provider(Component::Mysql, [new CollectorSpec($slow, Lifetime::Minute)]);

        $snapshot = $provider->get($instance);
        $provider->get($instance);

        self::assertFalse($snapshot->has('mysql.slow_queries'));
        self::assertSame('performance_schema table is not readable', $snapshot->result('mysql.slow_queries')?->error);
        self::assertSame(1, $slow->collects, 'the failure is cached too');
        self::assertSame(10, $this->cache->ttls[SnapshotProvider::cacheKey('mysql.slow_queries')], 'but only for the dynamic lifetime, not the collector one');
    }

    public function testAnUnreachableServerIsCachedForAMinute(): void
    {
        $unreachable = new UnreachableServerException('SQLSTATE[HY000] [2002] Connection timed out');
        $status = new FakeCollector('mysql.status', bootFailure: new BootFailedException('SHOW GLOBAL STATUS is not accessible', $unreachable));
        [$provider, $instance] = $this->provider(Component::Mysql, [new CollectorSpec($status, Lifetime::Dynamic)]);

        $snapshot = $provider->get($instance);

        self::assertSame('SHOW GLOBAL STATUS is not accessible (SQLSTATE[HY000] [2002] Connection timed out)', $snapshot->result('mysql.status')?->error);
        self::assertSame(60, $this->cache->ttls[SnapshotProvider::cacheKey('mysql.status')]);
    }

    public function testAnUnreachableServerIsCachedForThePollingIntervalWhenLonger(): void
    {
        $status = new FakeCollector('mysql.status', collectFailure: new UnreachableServerException('Connection timed out'));
        $provider = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($this->cache, 60), $this->cache, $this->clock, 120, 3600);

        $provider->get(Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [new CollectorSpec($status, Lifetime::Dynamic)])));

        self::assertSame(120, $this->cache->ttls[SnapshotProvider::cacheKey('mysql.status')]);
    }

    public function testAnInstanceIsCollectedByOneRequestAtATime(): void
    {
        $lockKey = 'jmonitor_easyadmin.collecting.mysql.default';
        $status = new FakeCollector('mysql.status', ['Questions' => 100]);
        $variables = new class ($this->cache, $lockKey) implements CollectorInterface {
            public ?bool $locked = null;

            public function __construct(
                private readonly RecordingCachePool $cache,
                private readonly string $lockKey,
            ) {}

            public function collect(): array
            {
                $this->locked = $this->cache->hasItem($this->lockKey);

                return ['version' => '8.4'];
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'mysql.variables';
            }
        };
        $instance = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [
            new CollectorSpec($status, Lifetime::Dynamic),
            new CollectorSpec($variables, Lifetime::Static),
        ]), 'default');

        $this->bareProvider()->get($instance);

        self::assertTrue($variables->locked, 'the instance is locked while collecting');
        self::assertFalse($this->cache->hasItem($lockKey), 'and released afterwards');
        self::assertSame(60, $this->cache->ttls[$lockKey], 'the lock of a request that died expires on its own');
    }

    public function testAnotherRequestSeesTheCachedResultsAndWaitsForTheOthers(): void
    {
        $status = new FakeCollector('mysql.status', ['Questions' => 100]);
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $instance = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [
            new CollectorSpec($status, Lifetime::Dynamic),
            new CollectorSpec($variables, Lifetime::Static),
        ]), 'default');
        $provider = $this->bareProvider();
        $provider->get($instance);
        $this->cache->deleteItem(SnapshotProvider::cacheKey('mysql.status', 'mysql.default.'));
        $this->cache->save($this->cache->getItem('jmonitor_easyadmin.collecting.mysql.default')->set(true));

        $snapshot = $provider->get($instance);

        self::assertSame(1, $status->collects, 'not collected while another request does');
        self::assertSame(SnapshotProvider::IN_PROGRESS, $snapshot->result('mysql.status')?->error);
        self::assertSame(['version' => '8.4'], $snapshot->data('mysql.variables'));
        self::assertFalse($this->cache->hasItem(SnapshotProvider::cacheKey('mysql.status', 'mysql.default.')), 'the placeholder is not cached');
        self::assertTrue($this->cache->hasItem('jmonitor_easyadmin.collecting.mysql.default'), 'nor the lock of the other request released');
    }

    public function testTheImplicitInstancesOfTwoComponentsHaveTheirOwnLock(): void
    {
        $this->cache->save($this->cache->getItem('jmonitor_easyadmin.collecting.system')->set(true));
        $php = new FakeCollector('php', ['version' => '8.4']);

        $snapshot = $this->bareProvider()->get(Instance::found(Component::Php, new CollectorSet(Component::Php, [new CollectorSpec($php, Lifetime::Dynamic)])));

        self::assertSame(1, $php->collects);
        self::assertTrue($snapshot->result('php')?->ok());
    }

    public function testTheCauseOfAWrappedFailureIsPartOfTheMessage(): void
    {
        $status = new FakeCollector('mysql.status', collectFailure: new BootFailedException('SHOW GLOBAL STATUS is not accessible', new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')));
        [$provider, $instance] = $this->provider(Component::Mysql, [new CollectorSpec($status, Lifetime::Dynamic)]);

        $snapshot = $provider->get($instance);

        self::assertSame('SHOW GLOBAL STATUS is not accessible (SQLSTATE[HY000] [2002] Connection refused)', $snapshot->result('mysql.status')?->error);
    }

    public function testACachedPayloadWithAnUnexpectedShapeIsCollectedAgain(): void
    {
        $status = new FakeCollector('mysql.status', ['Questions' => 100]);
        [$provider, $instance] = $this->provider(Component::Mysql, [new CollectorSpec($status, Lifetime::Dynamic)]);
        $item = $this->cache->getItem(SnapshotProvider::cacheKey('mysql.status'));
        $item->set(['payload' => 'from an older version of the bundle']);
        $this->cache->save($item);

        $snapshot = $provider->get($instance);

        self::assertSame(1, $status->collects);
        self::assertSame(['Questions' => 100], $snapshot->data('mysql.status'));
    }

    public function testRefreshForgetsTheComponent(): void
    {
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        [$provider, $instance] = $this->provider(Component::Mysql, [new CollectorSpec($variables, Lifetime::Static)]);

        $provider->get($instance);
        $provider->refresh($instance);
        $provider->get($instance);

        self::assertSame(2, $variables->collects);
    }

    public function testRefreshLetsACollectorDropWhatItCachesItself(): void
    {
        $collector = new class implements CollectorInterface, RefreshableCollectorInterface {
            public int $refreshes = 0;

            public function collect(): array
            {
                return ['version' => '1.4.0'];
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'frankenphp';
            }

            public function refresh(): void
            {
                $this->refreshes++;
            }
        };
        [$provider, $instance] = $this->provider(Component::FrankenPhp, [new CollectorSpec($collector, Lifetime::Dynamic)]);

        $provider->get($instance);
        $provider->refresh($instance);

        self::assertSame(1, $collector->refreshes);
        self::assertFalse($this->cache->hasItem(SnapshotProvider::cacheKey('frankenphp')));
    }

    public function testUnavailableComponentGivesAnEmptySnapshotWithTheReason(): void
    {
        $snapshot = $this->bareProvider()->get(Instance::unavailable(Component::Mysql, 'Connection refused'));

        self::assertSame(Component::Mysql, $snapshot->component);
        self::assertSame([], $snapshot->results);
        self::assertSame('Connection refused', $snapshot->error);
    }

    public function testTwoInstancesOfAComponentHaveSeparateCacheEntriesAndDeltas(): void
    {
        $shop = new FakeCollector('mysql.status', ['Questions' => 100]);
        $erp = new FakeCollector('mysql.status', ['Questions' => 5000]);
        $default = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [new CollectorSpec($shop, Lifetime::Dynamic, trackDelta: true)]), 'default');
        $legacy = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [new CollectorSpec($erp, Lifetime::Dynamic, trackDelta: true)]), 'legacy');
        $provider = $this->bareProvider();

        $provider->get($default);
        $this->clock->sleep(11);
        $legacySnapshot = $provider->get($legacy);

        self::assertSame(['Questions' => 5000], $legacySnapshot->data('mysql.status'));
        self::assertNull($legacySnapshot->delta('mysql.status'), 'the sample of the other instance is not its reference');
        self::assertArrayHasKey('jmonitor_easyadmin.collect.mysql.default.mysql.status', $this->cache->ttls);
        self::assertArrayHasKey('jmonitor_easyadmin.collect.mysql.legacy.mysql.status', $this->cache->ttls);
        self::assertSame(1, $shop->collects);
        self::assertSame(1, $erp->collects);
    }

    public function testRefreshingAnInstanceLeavesTheOthersCached(): void
    {
        $shop = new FakeCollector('mysql.status', ['Questions' => 100]);
        $erp = new FakeCollector('mysql.status', ['Questions' => 5000]);
        $default = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [new CollectorSpec($shop, Lifetime::Dynamic, trackDelta: true)]), 'default');
        $legacy = Instance::found(Component::Mysql, new CollectorSet(Component::Mysql, [new CollectorSpec($erp, Lifetime::Dynamic, trackDelta: true)]), 'legacy');
        $provider = $this->bareProvider();

        $provider->get($default);
        $provider->get($legacy);
        $provider->refresh($legacy);
        $provider->get($default);
        $provider->get($legacy);

        self::assertSame(1, $shop->collects);
        self::assertSame(2, $erp->collects);
    }

    public function testTheImplicitInstanceKeepsTheUnprefixedKeys(): void
    {
        $php = Instance::found(Component::Php, new CollectorSet(Component::Php, [new CollectorSpec(new FakeCollector('php', ['version' => '8.4']), Lifetime::Dynamic, trackDelta: true)]));

        $this->bareProvider()->get($php);

        self::assertArrayHasKey('jmonitor_easyadmin.collect.php', $this->cache->ttls);
        self::assertTrue($this->cache->hasItem('jmonitor_easyadmin.previous.php'));
    }

    /**
     * @param list<CollectorSpec> $specs
     *
     * @return array{SnapshotProvider, Instance}
     */
    private function provider(Component $component, array $specs): array
    {
        return [$this->bareProvider(), Instance::found($component, new CollectorSet($component, $specs))];
    }

    private function bareProvider(): SnapshotProvider
    {
        return new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($this->cache, 5), $this->cache, $this->clock, 10, 3600);
    }
}
