---
created: 2026-10-08T21:17:42.000Z
title: Install and configure Laravel Debugbar for development
area: tooling
severity: minor
files:
  - composer.json
  - config/app.php
  - phpunit.xml
---

## Problem

There is no in-browser profiler for the local DDEV environment. With Filament in SPA mode and Livewire-heavy pages (task list, kanban board), N+1 queries, slow queries and Livewire round-trips are hard to spot without one. The owner wants Laravel Debugbar available for development.

## Solution

TBD. Starting point: add `fruitcake/laravel-debugbar` as a **dev-only** dependency (`composer require --dev`), check that its license is AGPL-compatible (`ddev composer check-licenses` must stay green), enable it only for the local environment (`DEBUGBAR_ENABLED` driven by `APP_ENV=local` and `APP_DEBUG`, never in production or testing), set it to off in `phpunit.xml` so the test suite and the concurrency worker processes are unaffected, and verify it works with Filament SPA navigation and Livewire requests (query, model and Livewire collectors, no leakage of Partner data into any stored debugbar output). Keep `.env` values out of git (only document the variable in `.env.example`), and mention the tool in the contributor docs (`CONTRIBUTING.md`). Candidate for a quick task.
