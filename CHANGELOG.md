# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Minor style corrections.

## [1.4.0] - 2026-10-21

### Added

- Apache tab.
- Nginx tab.

### Fixed

- The FrankenPHP version is shown when the binary prints it with a doubled "v".

## [1.3.0] - 2026-10-20

### Added

- The version cards show the support status of the version, from endoflife.date.

### Changed

- The help links stand out from the card headers.

### Fixed

- The stylesheet and scripts are versioned, so a browser no longer keeps them after an upgrade.

## [1.2.1] - 2026-10-20

### Changed

- The MySQL server pills are named after their connections.
- A rate waiting for its second sample says so instead of showing a dash.

## [1.2.0] - 2026-10-19

### Added

- The MySQL and PostgreSQL tabs can leave out Doctrine connections with `exclude_connections`.

### Changed

- The MySQL tab shows each server once, then the selected database.
- An unreachable database server is tried once per collect and no longer by several requests at a time.
- The MySQL and PostgreSQL tabs no longer build every Doctrine connection on each request.
- A database connection opened by the monitoring is closed once collected.

### Fixed

- A MySQL `unix_socket` next to a remote host no longer counts as a local server.
- The arrow keys no longer leave the page when they browse the database drop-down.
- The live refresh resumes after the database drop-down is closed with Escape.

## [1.1.1] - 2026-10-17

### Fixed

- A FrankenPHP version that could not be read is logged and retried by Refresh.

## [1.1.0] - 2026-10-17

### Added

- FrankenPHP tab.
- PostgreSQL tab.
- The MySQL and PostgreSQL tabs show every Doctrine connection.
- The Redis tab shows several servers.
- `connections` option for MySQL, PostgreSQL and Redis.
- A Doctrine connection that cannot be loaded is logged as a warning.
- `permission` option.

### Changed

- A configured connection that cannot be used is shown with its error.
- An unknown Redis `service` no longer breaks the container.

### Fixed

- The error messages of a tab keep a margin under them.

### Deprecated

- `components.mysql.connection`: use `connections`.
- `components.redis.dsn` and `components.redis.service`: use `connections`.

## [1.0.1] - 2026-10-16

### Changed

- The Symfony tab cards explain why their collect failed.
- Disabled Symfony cards are hidden.
- `components.symfony.messenger` defaults to `false` before Symfony 7.2.

### Fixed

- The Scheduler card shows "No tasks" when there is none.
- No more full exception trace for the Symfony commands in debug mode.
