# Jmonitor EasyAdmin Bundle

This bundle adds **live monitoring pages** to your **[EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle)** backend: system, PHP, FrankenPHP, Apache, Nginx, MySQL, PostgreSQL, Redis and Symfony metrics, collected when you open the page and refreshed while it stays open.  
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

<p align="center">
  <a href="https://raw.githubusercontent.com/jmonitor/easyadmin-bundle/1.x/.github/assets/screenshot-symfony.png"><img src="https://raw.githubusercontent.com/jmonitor/easyadmin-bundle/1.x/.github/assets/screenshot-symfony.png" alt="Symfony tab of the monitoring page in EasyAdmin" width="48%"></a>
  <a href="https://raw.githubusercontent.com/jmonitor/easyadmin-bundle/1.x/.github/assets/screenshot-php.png"><img src="https://raw.githubusercontent.com/jmonitor/easyadmin-bundle/1.x/.github/assets/screenshot-php.png" alt="PHP tab of the monitoring page in EasyAdmin" width="48%"></a>
</p>

## Supported components

| Category          | Components                                                                                                                                                                                   |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Runtime           | ![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-000000?style=flat-square)                     |
| Web server        | ![Apache](https://img.shields.io/badge/Apache-D22128?style=flat-square&logo=apache&logoColor=white) ![Nginx](https://img.shields.io/badge/Nginx-009639?style=flat-square&logo=nginx&logoColor=white) |
| Framework         | ![Symfony](https://img.shields.io/badge/Symfony-000000?style=flat-square&logo=symfony&logoColor=white)                                                                                        |
| Databases & cache | ![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-4169E1?style=flat-square&logo=postgresql&logoColor=white) ![Redis](https://img.shields.io/badge/Redis-FF4438?style=flat-square&logo=redis&logoColor=white) |
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

Everything is optional. Components are detected from your application.

```yaml
# config/packages/jmonitor_easyadmin.yaml
jmonitor_easyadmin:
    cache_pool: cache.app      # PSR-6 pool keeping the collected metrics
    polling_interval: 10       # seconds between two live refreshes, 0 disables them
    static_ttl: 3600           # seconds during which versions, configuration and OS data are kept
    permission: ~              # role or voter attribute required to see the pages, e.g. ROLE_SUPER_ADMIN
    exclude_connections: []    # Doctrine connections the MySQL and PostgreSQL tabs leave out, e.g. '*_backup'
    components:
        system: auto           # auto | true | false
        php: auto
        frankenphp:
            enabled: auto
            endpoint: http://localhost:2019/metrics   # Prometheus endpoint of the Caddy admin API
        apache:
            enabled: auto
            endpoint: http://localhost/server-status  # mod_status page, read as ?auto
        nginx:
            enabled: auto
            endpoint: http://localhost/nginx_status   # stub_status page
        symfony:
            enabled: auto
            messenger: true    # runs "messenger:stats", defaults to true when Messenger is installed on Symfony 7.2+
            scheduler: true    # runs "debug:scheduler", defaults to true when Scheduler is installed
            flex: true         # runs "composer recipes", defaults to true when Flex is installed
        mysql:
            enabled: auto
            connections: []    # Doctrine connection names, empty = every MySQL/MariaDB connection
        postgresql:
            enabled: auto
            connections: []    # Doctrine connection names, empty = every PostgreSQL connection
            schema: public     # schema whose tables feed the storage, scans and dead rows cards
        redis:
            enabled: auto
            connections: []    # id => DSN, {dsn} or {service}; empty = the Symfony cache provider or REDIS_URL
```

`auto` shows the component when it is detected. `true` always shows it, with the detection error when it fails. `false` removes it.

`permission` restricts the pages to the users granted that role or voter attribute, on every dashboard, on top of the security of the dashboard itself; the others get a 403. Hide the menu link the same way: `MenuItem::linkToRoute(...)->setPermission('ROLE_SUPER_ADMIN')`.

`connections` lists the Doctrine connections to show, each one becoming a pill under the tabs (MySQL groups them by server: a pill per server, then the databases of the selected server); a connection named here is always shown, with its error when it cannot be read. A PostgreSQL connection may have its own schema: `connections: { default: ~, dwh: { schema: analytics } }`. The former `connection` option still works and is deprecated.

`exclude_connections` leaves out of the MySQL and PostgreSQL tabs the Doctrine connections whose name matches one of its patterns, with the `*` and `?` wildcards: the connections your application declares but does not serve, for instance `exclude_connections: ['*_backup']`. A connection listed in `connections` cannot match it.

For Redis, `connections` maps an id to a DSN, `{ dsn: ... }` or `{ service: ... }` (a `\Redis`, `\Predis\Client` or `\Relay\Relay` service), each one becoming a pill: `connections: { cache: 'redis://localhost', sessions: { dsn: 'redis://sessions:6379/1' }, queue: { service: app.redis_queue } }`.

## Compared to jmonitor.io

This bundle shows the **present** only: nothing is collected while nobody looks at the page, and nothing is kept.

[jmonitor.io](https://jmonitor.io), the cloud edition, collects your metrics continuously with a worker and keeps their **history**: the dashboards draw their charts, and **alerts** notify you by email when a threshold is crossed or a version reaches its end of life.

Both use the same collectors and run side by side: install [jmonitor/jmonitor-bundle](https://github.com/jmonitor/jmonitor-bundle), fill its API key and run its worker to send the same metrics to jmonitor.io.

## Good to know

- **System**: Linux only.
- **FrankenPHP**: needs the `metrics` global option in the Caddyfile (`{ metrics }`), and `frankenphp` in the `PATH` of the web process for the version.
- **Apache**: needs a `mod_status` page at `/server-status` (`SetHandler server-status`, `Require local`), left out of the rewrite to `index.php`. When Apache runs in another container than PHP, point `components.apache.endpoint` at it.
- **Nginx**: needs `location = /nginx_status { stub_status; allow 127.0.0.1; deny all; }` in the server block answering `localhost`. When Nginx runs in another container than PHP, point `components.nginx.endpoint` at it.
- **MySQL**: the slow queries need read access to the `performance_schema`.
- **PostgreSQL**: the slow queries need the `pg_stat_statements` extension and `GRANT pg_monitor TO <your role>`.
- **Symfony**: the Messenger stats need Symfony 7.2+, the Flex recipes `composer` in the `PATH` of the web process.
- **Load balancer**: the System, PHP, web server and Symfony tabs show the server that answered; in containers, give each one its own `hostname`.
- **Outbound requests**: the version badges come from [endoflife.date](https://endoflife.date) and the update badges of the Overview from GitHub, once a day; the requests carry no data about your application.

## Security

- The pages show data meant for administrators, such as query texts, which may hold personal data.
- They answer under every dashboard of the application, linked from its menu or not: with a dashboard for less trusted users, set `permission` or add an `access_control` rule on `^/<dashboard path>/jmonitor`.
- A dashboard `host` does not apply to these pages: restrict the host in the firewall or in `access_control`.
- Keep the Caddy admin API, the `mod_status` page and the `stub_status` page reachable from the web host only.
- With a Content-Security-Policy, allow `https://dash.jmonitor.io` in `frame-src` for the help panel.

## Need help?
- Anything about this bundle — installation, configuration, the pages in EasyAdmin: open an issue on this repo https://github.com/jmonitor/easyadmin-bundle/issues
- Anything about a metric — a missing or wrong value, a collector that does not gather what you expect: open an issue on https://github.com/jmonitor/collector/issues
- Anything about the help pages or jmonitor.io itself: open an issue on https://github.com/jmonitor/jmonitor/issues
- To report a vulnerability, see [SECURITY.md](SECURITY.md).
