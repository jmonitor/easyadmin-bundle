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
        yield 'socket' => [['unix_socket' => '/run/mysqld/mysqld.sock'], true];
        yield 'socket with localhost' => [['host' => 'localhost', 'unix_socket' => '/run/mysqld/mysqld.sock'], true];
        yield 'socket ignored by a remote host' => [['host' => 'db.internal', 'unix_socket' => '/run/mysqld/mysqld.sock'], false];
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

    /**
     * @return iterable<string, array{array<string, mixed>, array{string, string, ?int}}>
     */
    public static function servers(): iterable
    {
        yield 'no host' => [[], ['localhost:3306', 'localhost', 3306]];
        yield 'localhost' => [['host' => 'localhost'], ['localhost:3306', 'localhost', 3306]];
        yield 'uppercase host with default port' => [['host' => 'LOCALHOST', 'port' => 3306], ['localhost:3306', 'localhost', 3306]];
        yield 'remote host with a string port' => [['host' => 'db1.internal', 'port' => '3307'], ['db1.internal:3307', 'db1.internal', 3307]];
        yield 'socket' => [['host' => 'LocalHost', 'unix_socket' => '/run/mysqld/mysqld.sock'], ['socket:/run/mysqld/mysqld.sock', 'localhost', null]];
        yield 'socket without host' => [['unix_socket' => '/run/mysqld/mysqld.sock'], ['socket:/run/mysqld/mysqld.sock', 'localhost', null]];
        yield 'socket ignored by a remote host' => [['host' => 'db.internal', 'unix_socket' => '/run/mysqld/mysqld.sock'], ['db.internal:3306', 'db.internal', 3306]];
        yield 'primary' => [['primary' => ['host' => 'db1.internal'], 'replica' => [['host' => 'db2.internal']]], ['db1.internal:3306', 'db1.internal', 3306]];
    }

    /**
     * @param array<string, mixed>        $params
     * @param array{string, string, ?int} $expected
     */
    #[DataProvider('servers')]
    public function testServer(array $params, array $expected): void
    {
        $server = DoctrineParams::server($params);

        self::assertSame($expected, [$server->key, $server->host, $server->port]);
    }

    public function testASocketServerKeepsItsPath(): void
    {
        self::assertSame('/run/mysqld/mysqld.sock', DoctrineParams::server(['unix_socket' => '/run/mysqld/mysqld.sock'])->socket);
        self::assertNull(DoctrineParams::server(['host' => 'db.internal'])->socket);
    }
}
