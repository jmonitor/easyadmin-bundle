# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The Redis tab shows every server of the new `components.redis.connections` option (an id mapped to a DSN, `{dsn}` or `{service}`), one pill per server, selected with the `connection` query parameter, each one with its own cache and Refresh.
- The MySQL and PostgreSQL tabs show every Doctrine connection of their driver, one pill per database; the connection is selected with the `connection` query parameter (`/jmonitor/mysql?connection=legacy`), each one with its own cache and Refresh. The new `connections` option restricts them to the ones listed, and takes a `schema` per PostgreSQL connection.
- A PostgreSQL tab, detected from the Doctrine connections: connections, transactions, cache hit ratio, temp files, rows written, slow queries (`pg_stat_statements`), dead rows and index usage, blocked sessions and blocking chain, storage, with the configuration cards.

### Changed

- A Redis connection configured but unusable (unknown service, invalid or empty DSN) is shown with its error even when the component is `auto`; a `service` id that does not exist no longer breaks the container compilation.
- A Doctrine connection named in the configuration but unusable (unknown, or not of the component's driver) is shown with its error even when the component is `auto`, instead of hiding the component.

### Deprecated

- `components.mysql.connection` and `components.postgresql.connection`: use `connections` instead.
- `components.redis.dsn` and `components.redis.service`: use `connections` instead.

### Fixed

- The cumulated ratios and the deadlocks total of the PostgreSQL tab say "since stats reset" instead of "since server start": the `pg_stat_database` counters survive a restart and are cleared by `pg_stat_reset()`.
- Refresh on a component with a single connection goes back to the page without the `connection` parameter, as it was opened.
- Screen readers announce the "Detection failed" marker of a connection pill in error.
- The deprecated `connection` option of MySQL and PostgreSQL and the `dsn` and `service` options of Redis are merged into `connections` once every configuration file is loaded: a later file overrides them instead of adding a connection, and setting `connections` in another file is refused like in the same one.

## [1.0.1] - 2026-10-16

### Changed

- The Flex recipes, Messenger and Scheduler cards of the Symfony tab show why their collect failed (the command error) and how to fix or hide them, instead of "not collected".
- The cards of the Symfony sub-collectors disabled in the configuration are no longer shown.
- `components.symfony.messenger` defaults to `false` before Symfony 7.2, whose `messenger:stats` has no JSON output.

### Fixed

- The Scheduler card shows "No tasks" instead of "Scheduler tasks are not collected." when the scheduler has no task.
- In debug mode, the `messenger:stats` and `composer recipes` processes no longer run at debug verbosity, so their error is no longer followed by a full exception trace.
