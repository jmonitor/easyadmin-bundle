<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Detection;

use Jmonitor\EasyAdminBundle\Detection\DoctrineParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function addresses(): iterable
    {
        yield 'host and port' => [['host' => 'DB1.internal', 'port' => 3307], 'db1.internal:3307'];
        yield 'default port' => [['host' => '10.0.0.5'], '10.0.0.5:3306'];
        yield 'no host' => [[], 'localhost:3306'];
        yield 'socket' => [['unix_socket' => '/run/mysqld/mysqld.sock'], '/run/mysqld/mysqld.sock'];
        yield 'IPv6' => [['host' => '::1'], '[::1]:3306'];
        yield 'bracketed IPv6' => [['host' => '[::1]', 'port' => 3307], '[::1]:3307'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('addresses')]
    public function testAddress(array $params, string $address): void
    {
        self::assertSame($address, DoctrineParams::server($params)->address());
    }
}
