# Changelog

Every version of omnitravel/transitous, newest first ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
[Semantic Versioning](https://semver.org/)). A release's notes are its section here: the release
workflow refuses a tag without one.

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnitravel` `^1.0` with no stability flag, so `composer require omnitravel/transitous` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

Omnitravel's Transitous, under any PHP host, with no key: journeys from A to B, a stop's next departures and arrivals in real time (delays, tracks, cancellations), a stop and its lines, the stops near a point or by name - across the open timetables Transitous gathers worldwide, through its MOTIS 2 API, with the User-Agent its usage policy asks.
