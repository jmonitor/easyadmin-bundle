# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Monitoring pages inside EasyAdmin 5 with one tab per detected component: system, PHP, MySQL, Redis and Symfony.
- Live refresh of the current tab, with a switch in the page showing the interval and the time of the last update.
- Detection of MySQL from the Doctrine connections and of Redis from the Symfony cache provider or `REDIS_URL`.
- Help pages of the metrics, opened in a side panel from the `?` next to each metric.
