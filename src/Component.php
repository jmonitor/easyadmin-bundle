<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle;

enum Component: string
{
    case System = 'system';
    case Php = 'php';
    case FrankenPhp = 'frankenphp';
    case Apache = 'apache';
    case Nginx = 'nginx';
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
            self::Nginx => 'Nginx',
            self::Symfony => 'Symfony',
            self::Mysql => 'MySQL',
            self::Postgresql => 'PostgreSQL',
            self::Redis => 'Redis',
        };
    }

    /**
     * Read on the web server answering the request: behind a load balancer, each one has its own values.
     */
    public function isNodeLocal(): bool
    {
        return match ($this) {
            self::System, self::Php, self::FrankenPhp, self::Apache, self::Nginx, self::Symfony => true,
            self::Mysql, self::Postgresql, self::Redis => false,
        };
    }
}
