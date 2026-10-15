# CLAUDE.md

## Project Overview

This is a **Symfony bundle** (`jmonitor/easyadmin-bundle`) that adds live monitoring pages to an EasyAdmin 5 backend: system, PHP, MySQL, Redis and Symfony metrics collected when the page is opened and refreshed by polling while it stays open. No worker, no history, no charts: the present only.

It reuses the collectors of `jmonitor/collector` and the Symfony collectors of `jmonitor/jmonitor-bundle` (`SymfonyCollector`, `CommandRunner`, the messenger/scheduler/flex sub-collectors), instantiated by this bundle's own service wiring. It is **not** coupled to the jmonitor.io SaaS: no shared code with it, no project, no API key, no alerts. Copying a small piece of logic from the SaaS for the bundle's own use is fine (the gauge thresholds in `config/ranges.php` are such a copy); depending on it is not.

## Status

- v1 is implemented on `1.x`, not released yet.
- The `?` links default to `https://dash.jmonitor.io/help/metric/<metric key>`, the public help pages of the SaaS (served with `frame-ancestors *`). The page needs no JavaScript, so the iframe's `sandbox` grants no `allow-scripts`; this also keeps out the script Cloudflare injects into it.
- Before the first release: do a manual pass in a real EasyAdmin application (`public/live.js` and `public/help.js` were only verified in a browser against a harness reproducing the layout's markup), write the Flex recipe (the bundle currently relies on Flex's automatic bundle registration), add the README screenshot (`.github/assets/hero-easyadmin.png`, commented out in the README).
- Known minor follow-ups, none blocking: `CollectorRunner` runs `boot()` at every collect (one probe query per lifetime); detection builds the collector instances on every monitoring request even when the cache is warm (cheap objects, but a detection/construction split would avoid it); several web nodes sharing one cache pool show whichever node collected last, and the deltas of the node-local collectors (system, php) can mix two nodes; `live.js` and `help.js` have no automated test.

## Architecture

Request flow: `Controller\MonitoringController` → `Detection\ComponentRegistry` → `Snapshot\SnapshotProvider` → `View\ViewFactory` → Twig.

- **Routes**: the controller carries `#[AdminRoute('/jmonitor', name: 'jmonitor')]` and is tagged `ea.admin_route_controller`; EasyAdmin creates `<dashboard>_jmonitor_index`, `_component` (`/{component}`), `_fragment` (`/{component}/fragment`) and `_refresh` (POST) under every dashboard. The dashboard route name comes from `AdminContextProviderInterface::getContext()->getDashboardRouteName()`. Access is left to the application's security, like its CRUD controllers (an `access_control` rule on the dashboard path).
- **Detection** (`src/Detection/`): one `ComponentDetectorInterface` per `Component` enum case, tagged `jmonitor_easyadmin.detector` with the component value as index. `ComponentRegistry` applies the `enabled` setting (`auto` keeps what is found, `true` keeps the component with its error, `false` drops it), memoizes per request and implements `ResetInterface`. Detection never touches the network: MySQL reads the Doctrine connection parameters (`driver` `pdo_mysql`/`mysqli` or a MySQL `driverClass`, `dbname`), Redis opens its DSN lazily (`?lazy=1`), so a dead server shows up as a collect error inside the tab. A MySQL connection without host, on a loopback host or through a `unix_socket` counts as local: on Linux it also gets `HostRamCollector` (`system.ram_total`, static), the only source of the InnoDB RAM ratio, which is hidden otherwise. `DependencyInjection\DoctrineConnectionsPass` fills the MySQL detector's connection locator from the `doctrine.connections` parameter; `JmonitorEasyAdminBundle::prependExtension()` reads `framework.cache.default_redis_provider`.
- **Collection** (`src/Collector/`, `src/Snapshot/`): a `CollectorSet` lists `CollectorSpec`s (collector, `Lifetime`, `trackDelta`). `Lifetime::Dynamic` = `polling_interval` seconds (min 1), `Minute` = 60, `Static` = `static_ttl`. `SnapshotProvider` caches each collector's `CollectorResult` under `jmonitor_easyadmin.collect.<name>` in the configured PSR-6 pool (`cache.app` by default); a failure is cached for the dynamic lifetime and its message carries the cause of a wrapped exception. `DeltaCalculator` keeps a reference sample (`jmonitor_easyadmin.previous.<name>`) and yields a `Delta` when the new sample is between 1 second and `max(300, 2 × polling_interval)` seconds after it; a sample less than `polling_interval / 2` after the reference (a Refresh) is compared with it without replacing it; a counter that went down gives no value.
- **Metrics** (`src/Metric/`): metric keys are the SaaS's (`system.ram_usage`, `php.opcache_hit_rate`, `mysql.connections_usage`, `redis.hit_rate`…); they key the thresholds in `config/ranges.php` (`Ranges`, `Badge`) and the help links. `Ratios` computes percentages from the delta when it has traffic, else from the counters cumulated since the server started (`Rate::isSinceStart()`, shown as a hint); `GaugeFactory` turns values and rates into `Gauge`s.
- **Views** (`src/View/`): one read-only view per component (`SystemView`, `PhpView`, `MysqlView`, `RedisView`, `SymfonyView`) built from a `ComponentSnapshot`; `Values` normalizes the payload types. Templates contain no logic beyond formatting: badges are `Badge` objects built by the views, the peak bars a `Metric\Peak`.
- **Templates** (`templates/`, namespace `@JmonitorEasyAdmin`): `layout.html.twig` extends `@EasyAdmin/page/content.html.twig` and defines the inner block `jm_content` (EasyAdmin owns the `content` block, never override it); `fragment.html.twig` renders only `jm_content` for the polling; `component/*.html.twig` extend one or the other depending on the `fragment` variable; partials `_card` (title, `?`, badge), `_value_card`, `_sub_value`, `_gauge` (SVG arc, optional detail rows, `bare` inside another card), `_peak` (SVG bar with a peak marker), `_badge`, `_help`, `_rate`, `_kv` (a `Badge` value renders as a badge), `_value`, `_errors`. Twig helpers in `Twig\MonitoringExtension`: `jm_bytes`, `jm_duration`, `jm_number`, `jm_ago`, `jm_help_url()`, `jm_help_enabled()`.
- **Front** (`public/`, served from `bundles/jmonitoreasyadmin/` after `assets:install`): `live.js` is vanilla (polls the fragment every `polling_interval` seconds, pauses when the tab is hidden, remembers the Live switch in `localStorage['jmonitor_easyadmin.live']`, keeps the active Bootstrap tab and the scroll of the `.jm-scroll` cards, queries and tables across refreshes (by rank, only when their number did not change), skips a refresh while text is selected in the content, reloads the page on a redirect (fetched with `redirect: 'manual'`, so an SSO on another origin is caught too) or a 401/403 so the firewall shows its login page, goes to the index route on a 404 (the component is gone), and shows "refresh failed" next to "Last update" while refreshes fail); `help.js` opens the `?` links in an offcanvas holding a sandboxed iframe, with `?theme=light|dark` taken from EasyAdmin's `data-bs-theme`, and moves the panel under `<body>`; the link keeps its `href` and `target="_blank"`, so a modified click, a missing `window.bootstrap` or a blocked frame still reaches the page; `monitoring.css` styles the gauges with Bootstrap CSS variables. No Chart.js, no Stimulus, no AssetMapper dependency.
- **Wiring**: everything is declared by hand in `config/services.php` (`autowire(false)`), reading the processed configuration from the `jmonitor_easyadmin.config` parameter. The collectors log through `Logging\MinimumLevelLogger`, which forwards warnings and above to the application logger (channel `jmonitor_easyadmin`): sub-collector failures of the Symfony tab land in the application logs, the collectors' per-collect notices do not.
- **Robustness rules**: a detector that throws becomes an unavailable component, never a 500 on the whole monitoring; a cached payload whose shape is unknown counts as a miss; the Refresh form carries a CSRF token (`MonitoringController::REFRESH_TOKEN_ID`) checked when the token manager exists; the Redis provider of the Symfony cache may be a DSN or a service id.

## Tests

- `tests/Fixtures/TestKernel.php` boots the full stack (FrameworkBundle, Twig, TwigExtra, TwigComponent, Security, Doctrine on SQLite in memory, EasyAdmin, this bundle) with a configurable bundle and framework configuration; `tests/Fixtures/App/AdminKernel.php` derives from it for `WebTestCase`, with MySQL, Redis and the Symfony sub-collectors disabled, and reads `JMONITOR_TEST_POLLING` (see `PollingDisabledTest`). The test dashboard lives at `/admin` with route name `admin`; in-memory users `admin` (`ROLE_ADMIN`) and `viewer`. `tests/Fixtures/App/ConfigurableAdminKernel.php` takes its bundle configuration, and optionally a throwing MySQL detector, from static properties (see `RobustnessTest`).
- Kernel-booting tests call `restore_exception_handler()` in `tearDown()`: FrameworkBundle leaves an exception handler behind on Symfony 8.0 and PHPUnit reports it as risky.
- The MySQL and Redis tabs cannot be reached by the functional tests; `tests/Twig/ComponentTemplatesTest.php` renders their fragments from fixture payloads with strict variables on.
- There is no JavaScript test runner.

## Commands

Never prepend `cd [path]` before commands, and never use `git -C "[path]"` for git commands. The shell is already running at the project root.

```bash
composer phpunit        # tests
composer phpstan        # static analysis (level 8)
composer lint:check     # PHP-CS-Fixer check (@PER-CS)
composer lint:fix
./vendor/bin/phpunit tests/path/to/TestFile.php   # single test file
```

Without PHP on the host, run them through a container, for instance:

```bash
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app <php 8.2+ image with composer> composer phpunit
```

CI (`.github/workflows/tests.yml`) runs PHPStan and PHPUnit on PHP 8.2 to 8.5 and Symfony 6.4, 7.4 and 8.1, plus a `--prefer-lowest` job checking the floors of `composer.json`, for pushes to `*.x` branches and pull requests; the lint runs once, on the newest combination.

## Conventions

- The tabs show the cards of the jmonitor.io dashboard and nothing else: same metric keys, titles, fields and badges, minus the time series. A field the SaaS card does not have has no help page behind its `?`. The SaaS's line charts become their current value. Deliberate departures, asked for: the System informations card lists hostname, OS, server time, timezone and uptime and ends with the 1, 5 and 15-minute load average tiles, and the CPU gauge shows the number of cores instead of the load average; the MySQL RAM ratio is only shown when MySQL runs on the web host.
- `declare(strict_types=1)` in every file, full type declarations, PHP 8.2 floor (EasyAdmin 5's own floor).
- Readonly classes for immutable services, constructor injection, attributes for routes.
- Services must be safe under FrankenPHP worker mode: no per-request state kept in shared services unless they implement `ResetInterface` and are tagged `kernel.reset`.
- Never use Yoda conditions.
- Comments: short, factual, only when they say something the code does not.
- Development branch `1.x`; feature branches are merged through pull requests so the CI matrix runs.
- Commit messages and pull request bodies carry no session link such as a `Claude-Session:` trailer.
- This repository is public: no local path, private repository, private project or customer name in tracked files, commit messages or pull requests.

## CHANGELOG

Record user-visible changes under `## [Unreleased]`, creating the section if absent. Never write a version number or a date in `CHANGELOG.md`; a release assigns one by renaming `## [Unreleased]`.

## Documentation

`README.md` is the public documentation. Keep it in sync whenever a change affects the end user (new option, changed behavior, upgrade step).
