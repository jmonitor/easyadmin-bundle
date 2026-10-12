# JMonitor EasyAdmin bundle

Live monitoring pages inside your [EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle) backend: system, PHP, MySQL, Redis and Symfony metrics, collected when you open the page and refreshed while it stays open. No worker to run, no external service, no history to store.

It reuses the collectors of [jmonitor/collector](https://github.com/jmonitor/collector) and [jmonitor/jmonitor-bundle](https://github.com/jmonitor/jmonitor-bundle), the same ones that feed [jmonitor.io](https://jmonitor.io). This bundle shows the present; jmonitor.io keeps the history, draws the charts and sends the alerts. Both can run side by side.

## Requirements

- PHP 8.2+
- Symfony 6.4+
- EasyAdmin 5

## Installation

```bash
composer require jmonitor/easyadmin-bundle
php bin/console assets:install
```

Symfony Flex registers the bundle. Then add a link in your dashboard controller (`admin` is the route name of your dashboard, adapt it):

```php
yield MenuItem::linkToRoute('Monitoring', 'internal:desktop', 'admin_jmonitor_index');
```

The pages live under your dashboard, at `/admin/jmonitor`, behind the same firewall. They also require a role, `ROLE_ADMIN` by default.

`jmonitor/jmonitor-bundle` comes along as a dependency for its Symfony collectors. Leave its API key empty, or fill it to send the same metrics to jmonitor.io and get the history.

## What you get

One tab per detected component:

- **System**: RAM, CPU and disk gauges, OS, uptime, load average.
- **PHP**: version and configuration of the PHP process serving the page, OPcache memory and hit rate, APCu memory and hit rate, PHP-FPM processes and memory peak, loaded extensions.
- **MySQL** (or MariaDB): connections, InnoDB buffer pool usage and hit rate, temporary tables on disk, thread cache misses, table lock waits, queries and InnoDB reads/writes per second, slow queries from the performance schema, key variables.
- **Redis**: memory, hit rate, operations per second, clients, persistence, keyspaces.
- **Symfony**: version, environment, bundles, Messenger transports, Scheduler tasks, outdated Flex recipes, and a few recommendations.

Gauges carry a badge computed from typical thresholds. Rates and ratios are computed between two refreshes; before the second one, ratios fall back to the values cumulated since the server started. Each metric links to its help page on jmonitor.io.

## Configuration

Everything is optional. Components are detected from your application: Doctrine connections for MySQL, the Redis provider of the Symfony cache or the `REDIS_URL` env var for Redis, the PHP process for PHP, `/proc` for the system.

```yaml
# config/packages/jmonitor_easyadmin.yaml
jmonitor_easyadmin:
    cache_pool: cache.app      # PSR-6 pool keeping the collected metrics
    polling_interval: 10       # seconds between two live refreshes, 0 disables them
    static_ttl: 3600           # seconds during which versions, configuration and OS data are kept
    role: ROLE_ADMIN           # role required to open the pages
    help_url: https://jmonitor.io/help/metric/   # false hides the help links
    components:
        system: auto           # auto | true | false
        php: auto
        symfony:
            enabled: auto
            messenger: true    # runs "messenger:stats"
            scheduler: true    # runs "debug:scheduler"
            flex: true         # runs "composer recipes"
        mysql:
            enabled: auto
            connection: null   # a Doctrine connection name, defaults to the first MySQL one
        redis:
            enabled: auto
            dsn: null          # redis://localhost
            service: null      # or the id of a \Redis, \Predis\Client or \Relay\Relay service
```

`auto` shows the component when it is detected. `true` always shows it, with the detection error when it fails. `false` removes it.

## How it works

Opening a tab runs the component's collectors and caches the result: fast-changing data for `polling_interval` seconds, versions and configuration for `static_ttl` seconds. Every admin looking at the page shares that cache, so a tab left open costs one collection per interval. The **Refresh** button drops the component's cache, for instance after a server restart. The **Live** button pauses the polling; the choice is remembered in the browser.

The Symfony tab runs console commands (`messenger:stats`, `debug:scheduler`, `composer recipes`) and is cached for `static_ttl`; disable the ones you do not want.

## License

MIT
