# CLAUDE.md

## Project Overview

This is a **Symfony bundle** (`jmonitor/easyadmin-bundle`) that adds live monitoring pages to an EasyAdmin 5 backend: system, PHP, MySQL, Redis and Symfony metrics collected when the page is opened and refreshed by polling while it stays open. No worker, no history, no charts: the present only.

It reuses the collectors of `jmonitor/collector` and the Symfony collectors of `jmonitor/jmonitor-bundle`. It is **not** coupled to the jmonitor SaaS (`jmonitor/jmonitor`): no shared code with it, no project, no API key, no alerts. Copying a small piece of logic from the SaaS for the bundle's own use is fine; depending on it is not.

## Commands

Never prepend `cd [path]` before commands, and never use `git -C "[path]"` for git commands. The shell is already running at the project root.

```bash
composer phpunit        # tests
composer phpstan        # static analysis (level 5)
composer lint:check     # PHP-CS-Fixer check (@PER-CS)
composer lint:fix
./vendor/bin/phpunit tests/path/to/TestFile.php   # single test file
```

## Conventions

- `declare(strict_types=1)` in every file, full type declarations, PHP 8.2 floor (EasyAdmin 5's own floor).
- Readonly classes for immutable services, constructor injection, attributes for routes.
- Services must be safe under FrankenPHP worker mode: no per-request state kept in shared services.
- Never use Yoda conditions.
- Comments: short, factual, only when they say something the code does not.

## CHANGELOG

Record user-visible changes under `## [Unreleased]`, creating the section if absent. Never write a version number or a date in `CHANGELOG.md`; a release assigns one by renaming `## [Unreleased]`.

## Documentation

`README.md` is the public documentation. Keep it in sync whenever a change affects the end user (new option, changed behavior, upgrade step).
