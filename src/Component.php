<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle;

enum Component: string
{
    case System = 'system';
    case Php = 'php';
    case Symfony = 'symfony';
    case Mysql = 'mysql';
    case Redis = 'redis';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Php => 'PHP',
            self::Symfony => 'Symfony',
            self::Mysql => 'MySQL',
            self::Redis => 'Redis',
        };
    }
}
