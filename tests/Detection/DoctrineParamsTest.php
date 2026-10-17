<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Detection\DoctrineParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoctrineParamsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'no host' => [[], true];
        yield 'localhost' => [['host' => 'localhost'], true];
        yield 'loopback' => [['host' => '127.0.0.1'], true];
        yield 'IPv6 loopback' => [['host' => '::1'], true];
        yield 'socket' => [['host' => 'db.internal', 'unix_socket' => '/run/mysqld/mysqld.sock'], true];
        yield 'socket directory host' => [['host' => '/var/run/postgresql'], true];
        yield 'remote host' => [['host' => 'db.internal'], false];
        yield 'compose service' => [['host' => 'database'], false];
        yield 'remote primary' => [['primary' => ['host' => 'db.internal']], false];
        yield 'local primary' => [['primary' => ['host' => 'localhost']], true];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('hosts')]
    public function testIsLocal(array $params, bool $local): void
    {
        self::assertSame($local, DoctrineParams::isLocal($params));
    }
}
