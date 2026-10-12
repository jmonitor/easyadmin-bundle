# JMonitor EasyAdmin bundle

> **Work in progress.** Nothing is released yet; the API and the configuration below can still change.

Live monitoring pages inside your [EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle) backend: system, PHP, MySQL, Redis and Symfony metrics, collected on the fly when you open the page and refreshed while it stays open. No worker to run, no external service, no history to store.

It reuses the collectors of [jmonitor/collector](https://github.com/jmonitor/collector) and [jmonitor/jmonitor-bundle](https://github.com/jmonitor/jmonitor-bundle), the same ones that feed [jmonitor.io](https://jmonitor.io). This bundle shows the present; jmonitor.io keeps the history, draws the charts and sends the alerts. Both can run side by side.

## Requirements

- PHP 8.2+
- Symfony 6.4+
- EasyAdmin 5

## Installation

```bash
composer require jmonitor/easyadmin-bundle
```

Then add a link to the monitoring pages in your dashboard controller (`admin` is the route name of your dashboard, adapt it):

```php
yield MenuItem::linkToRoute('Monitoring', 'fa fa-heart-pulse', 'admin_jmonitor_index');
```

## Configuration

Everything is optional. By default the components are detected from your application: Doctrine connections for MySQL, the Symfony cache configuration or `REDIS_URL` for Redis, the PHP process serving the page for PHP and OPcache, `/proc` for the system.

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
            connection: null   # a Doctrine DBAL connection service id, defaults to the first MySQL one
        redis:
            enabled: auto
            dsn: null          # redis://localhost
            service: null      # or the id of a \Redis, \Predis\Client or \Relay\Relay service
```

## License

MIT
