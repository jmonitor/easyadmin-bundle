<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle;

enum Component: string
{
    case System = 'system';
    case Php = 'php';
    case FrankenPhp = 'frankenphp';
    case Apache = 'apache';
    case Symfony = 'symfony';
    case Mysql = 'mysql';
    case Postgresql = 'postgresql';
    case Redis = 'redis';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Php => 'PHP',
            self::FrankenPhp => 'FrankenPHP',
            self::Apache => 'Apache',
            self::Symfony => 'Symfony',
            self::Mysql => 'MySQL',
            self::Postgresql => 'PostgreSQL',
            self::Redis => 'Redis',
        };
    }
}
