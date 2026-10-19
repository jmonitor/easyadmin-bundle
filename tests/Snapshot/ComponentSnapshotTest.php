<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Snapshot;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Snapshot\CollectorResult;
use Jmonitor\EasyAdminBundle\Snapshot\ComponentSnapshot;
use Jmonitor\EasyAdminBundle\Snapshot\Delta;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use PHPUnit\Framework\TestCase;

final class ComponentSnapshotTest extends TestCase
{
    public function testAccessors(): void
    {
        $delta = new Delta(['Questions' => 1], ['Questions' => 11], 10);
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', ['Questions' => 11], null, 1_000, $delta),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], 'performance_schema table is not readable', 1_000),
        ]);

        self::assertTrue($snapshot->has('mysql.status'));
        self::assertFalse($snapshot->has('mysql.slow_queries'));
        self::assertFalse($snapshot->has('mysql.variables'));
        self::assertSame(['Questions' => 11], $snapshot->data('mysql.status'));
        self::assertSame([], $snapshot->data('mysql.variables'));
        self::assertSame($delta, $snapshot->delta('mysql.status'));
        self::assertNull($snapshot->delta('mysql.slow_queries'));
        self::assertSame(['mysql.slow_queries: performance_schema table is not readable'], $snapshot->errors());
        self::assertNull($snapshot->error);
    }

    public function testCollectorsLeftToAnotherRequestAreNotErrors(): void
    {
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql.status' => new CollectorResult('mysql.status', [], SnapshotProvider::IN_PROGRESS, 1_000),
            'mysql.variables' => new CollectorResult('mysql.variables', [], SnapshotProvider::IN_PROGRESS, 1_000),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], 'performance_schema table is not readable', 1_000),
        ]);

        self::assertTrue($snapshot->inProgress());
        self::assertSame(['mysql.slow_queries: performance_schema table is not readable'], $snapshot->errors());
        self::assertFalse((new ComponentSnapshot(Component::Mysql, []))->inProgress());
    }

    public function testResultRoundTripsThroughArrays(): void
    {
        $result = new CollectorResult('redis', ['a' => 1], null, 1_000, new Delta(['a' => 0], ['a' => 1], 10));
        $copy = CollectorResult::fromArray('redis', $result->toArray());

        self::assertSame(['a' => 1], $copy->data);
        self::assertNull($copy->error);
        self::assertSame(1_000, $copy->collectedAt);
        self::assertSame(1, $copy->delta?->getValue('a'));

        $failed = CollectorResult::fromArray('redis', (new CollectorResult('redis', [], 'boom', 5))->toArray());
        self::assertSame('boom', $failed->error);
        self::assertNull($failed->delta);
    }
}
