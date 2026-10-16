# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The Flex recipes, Messenger and Scheduler cards of the Symfony tab show why their collect failed (the command error) and how to fix or hide them, instead of "not collected".
- The cards of the Symfony sub-collectors disabled in the configuration are no longer shown.
- `components.symfony.messenger` defaults to `false` before Symfony 7.2, whose `messenger:stats` has no JSON output.

### Fixed

- The Scheduler card shows "No tasks" instead of "Scheduler tasks are not collected." when the scheduler has no task.
