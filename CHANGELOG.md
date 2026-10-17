# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- PostgreSQL tab.
- The MySQL and PostgreSQL tabs show every Doctrine connection.
- The Redis tab shows several servers.
- `connections` option for MySQL, PostgreSQL and Redis.
- A Doctrine connection that cannot be loaded is logged as a warning.

### Changed

- A configured connection that cannot be used is shown with its error.
- An unknown Redis `service` no longer breaks the container.

### Deprecated

- `components.mysql.connection`: use `connections`.
- `components.redis.dsn` and `components.redis.service`: use `connections`.

## [1.0.1] - 2026-10-16

### Changed

- The Flex recipes, Messenger and Scheduler cards of the Symfony tab show why their collect failed (the command error) and how to fix or hide them, instead of "not collected".
- The cards of the Symfony sub-collectors disabled in the configuration are no longer shown.
- `components.symfony.messenger` defaults to `false` before Symfony 7.2, whose `messenger:stats` has no JSON output.

### Fixed

- The Scheduler card shows "No tasks" instead of "Scheduler tasks are not collected." when the scheduler has no task.
- In debug mode, the `messenger:stats` and `composer recipes` processes no longer run at debug verbosity, so their error is no longer followed by a full exception trace.
