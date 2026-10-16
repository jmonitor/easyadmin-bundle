# Jmonitor EasyAdmin Bundle

This bundle adds **live monitoring pages** to your **[EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle)** backend: system, PHP, MySQL, Redis and Symfony metrics, collected when you open the page and refreshed while it stays open.  
No worker to run, nothing to store.

[![Packagist Version](https://img.shields.io/packagist/v/jmonitor/easyadmin-bundle?style=flat-square)](https://packagist.org/packages/jmonitor/easyadmin-bundle)
[![Tests](https://img.shields.io/github/actions/workflow/status/jmonitor/easyadmin-bundle/tests.yml?branch=1.x&label=tests&style=flat-square)](https://github.com/jmonitor/easyadmin-bundle/actions)
[![License](https://img.shields.io/github/license/jmonitor/easyadmin-bundle?style=flat-square)](LICENSE)
[![Last Commit](https://img.shields.io/github/last-commit/jmonitor/easyadmin-bundle?style=flat-square)](https://github.com/jmonitor/easyadmin-bundle/commits)

<table>
  <tr>
    <th valign="top"><a href="https://github.com/jmonitor/jmonitor">jmonitor/jmonitor</a><br>&nbsp;</th>
    <th valign="top"><a href="https://github.com/jmonitor/collector">jmonitor/collector</a><br>&nbsp;</th>
    <th valign="top"><a href="https://github.com/jmonitor/jmonitor-bundle">jmonitor/jmonitor-bundle</a><br>&nbsp;</th>
  </tr>
  <tr>
    <td>Self-hostable backend — not needed with the cloud version</td>
    <td>The collectors — install them in the project to monitor</td>
    <td>Symfony-specific integration of the collectors</td>
  </tr>
  <tr>
    <td colspan="3" align="center">
      <b>jmonitor/easyadmin-bundle</b> <img src="https://img.shields.io/badge/you_are_here-0969da?style=flat-square" alt="you are here"><br>
      Live monitoring pages inside EasyAdmin — no backend, no worker
    </td>
  </tr>
  <tr>
    <td colspan="3" align="center">
      <a href="https://jmonitor.io">https://jmonitor.io</a>
    </td>
  </tr>
</table>

<!-- <img src=".github/assets/hero-easyadmin.png" alt="Jmonitor monitoring pages in EasyAdmin" width="700"> -->

## Supported components

| Category          | Components                                                                                                                                                                                   |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Runtime           | ![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white)                                                                                                    |
| Framework         | ![Symfony](https://img.shields.io/badge/Symfony-000000?style=flat-square&logo=symfony&logoColor=white)                                                                                        |
| Databases & cache | ![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white) ![Redis](https://img.shields.io/badge/Redis-FF4438?style=flat-square&logo=redis&logoColor=white) |
| System            | ![Linux](https://img.shields.io/badge/CPU%20·%20RAM%20·%20Disk-FCC624?style=flat-square&logo=linux&logoColor=black)                                                                           |

## Requirements
- [![PHP Version](https://img.shields.io/packagist/php-v/jmonitor/easyadmin-bundle?style=flat-square&label=PHP)](https://packagist.org/packages/jmonitor/easyadmin-bundle)
- [![Symfony Version](https://img.shields.io/packagist/dependency-v/jmonitor/easyadmin-bundle/symfony%2Fframework-bundle?style=flat-square&label=Symfony)](https://packagist.org/packages/jmonitor/easyadmin-bundle)
- [![EasyAdmin Version](https://img.shields.io/packagist/dependency-v/jmonitor/easyadmin-bundle/easycorp%2Feasyadmin-bundle?style=flat-square&label=EasyAdmin)](https://packagist.org/packages/jmonitor/easyadmin-bundle)

## Getting started

### 1. Install the bundle

```bash
composer require jmonitor/easyadmin-bundle
```

If you do not use Symfony Flex, add the bundle to `config/bundles.php` and install its assets:

```php
return [
    // ...
    Jmonitor\EasyAdminBundle\JmonitorEasyAdminBundle::class => ['all' => true],
];
```

```bash
php bin/console assets:install
```

### 2. Add a menu link

In your dashboard controller, link to the `<dashboard route>_jmonitor_index` route (`admin` is the route name of your dashboard, adapt it):

```php
public function configureMenuItems(): iterable
{
    // ...
    yield MenuItem::linkToRoute('Monitoring', 'internal:desktop', 'admin_jmonitor_index');
}
```

The pages live under the path of your dashboard (`/admin/jmonitor` for a dashboard at `/admin`), so the `access_control` rule protecting your backend covers them.

## Configuration

Everything is optional. Components are detected from your application: the Doctrine connections for MySQL, the Redis provider of the Symfony cache or the `REDIS_URL` env var for Redis, the PHP process for PHP, `/proc` for the system.

```yaml
# config/packages/jmonitor_easyadmin.yaml
jmonitor_easyadmin:
    cache_pool: cache.app      # PSR-6 pool keeping the collected metrics
    polling_interval: 10       # seconds between two live refreshes, 0 disables them
    static_ttl: 3600           # seconds during which versions, configuration and OS data are kept
    components:
        system: auto           # auto | true | false
        php: auto
        symfony:
            enabled: auto
            messenger: true    # runs "messenger:stats", defaults to true when Messenger is installed
            scheduler: true    # runs "debug:scheduler", defaults to true when Scheduler is installed
            flex: true         # runs "composer recipes", defaults to true when Flex is installed
        mysql:
            enabled: auto
            connection: null   # a Doctrine connection name, defaults to the first MySQL one
        redis:
            enabled: auto
            dsn: null          # redis://localhost
            service: null      # or the id of a \Redis, \Predis\Client or \Relay\Relay service
```

`auto` shows the component when it is detected. `true` always shows it, with the detection error when it fails. `false` removes it.

## Compared to jmonitor.io

This bundle shows the **present** only: nothing is collected while nobody looks at the page, and nothing is kept.

[jmonitor.io](https://jmonitor.io), the cloud edition (also [self-hostable](https://github.com/jmonitor/jmonitor)), collects your metrics continuously with a worker and keeps their **history**: the dashboards draw their charts, and **alerts** notify you by email when a threshold is crossed or a version reaches its end of life. It also covers Apache, Nginx, Caddy, FrankenPHP and PostgreSQL.

Both use the same collectors and run side by side: install [jmonitor/jmonitor-bundle](https://github.com/jmonitor/jmonitor-bundle), fill its API key and run its worker to send the same metrics to jmonitor.io.

## Good to know

- **System**: Linux only, read from `/proc`.
- **PHP**: the metrics are those of the PHP process serving the admin. The PHP-FPM section needs PHP-FPM, the APCu cards the APCu extension.
- **MySQL**: the slow queries are read from the `performance_schema`; without access to it, that card shows an error and the rest of the tab still works. The InnoDB RAM ratio is only shown on Linux, when MySQL runs on the web host (no host, a loopback host or a Unix socket).
- **Symfony**: the scheduler is read in the web process. The Messenger stats run `bin/console messenger:stats` in a PHP CLI process started by the web server, and the Flex recipes check needs `composer` in its `PATH`.

## How it works

Opening a tab runs the component's collectors and caches the result: fast-changing data for `polling_interval` seconds, versions and configuration for `static_ttl` seconds. The **Refresh** button drops the cache, for instance after a server restart.

Several web servers behind a load balancer are not well handled yet: the System and PHP values depend on the server that answered, and may change from one refresh to the next.

## Security

- The pages are protected like the rest of your backend, by your own security configuration (an `access_control` rule on the path of the dashboard, as recommended by EasyAdmin). They show data meant for administrators: slow query samples with their literal values (which may hold personal data), the MySQL user and host in driver errors, the paths of the PHP INI files.
- The pages are reachable under every dashboard of the application, whether its menu links to them or not: with a second dashboard for less trusted users, they answer there too.
- EasyAdmin does not carry the `host` requirement of a dashboard over to these routes: on a dashboard restricted to one host, the pages also answer on the other hosts.
- With a Content-Security-Policy, allow `https://dash.jmonitor.io` in `frame-src`, where the help pages behind the `?` links live; otherwise the help panel stays empty and its "Open in a new tab" link still works. The bundle loads its scripts from `bundles/jmonitoreasyadmin/`, with a nonce when `csp_nonce()` is available (NelmioSecurityBundle), and uses no inline script or style.

## Need help?
- Anything about this bundle — installation, configuration, the pages in EasyAdmin: open an issue on this repo https://github.com/jmonitor/easyadmin-bundle/issues
- Anything about a metric — a missing or wrong value, a collector that does not gather what you expect: open an issue on https://github.com/jmonitor/collector/issues
- Anything about the help pages or jmonitor.io itself: open an issue on https://github.com/jmonitor/jmonitor/issues
- To report a vulnerability, see [SECURITY.md](SECURITY.md).
