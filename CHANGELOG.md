# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The MySQL and PostgreSQL tabs can leave out Doctrine connections with `exclude_connections`.

### Changed

- The MySQL tab shows each server once, then the selected database.
- An unreachable database server is tried once per collect and no longer by several requests at a time.

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
