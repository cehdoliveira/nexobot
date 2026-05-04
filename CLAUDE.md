# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**NexoFW** is a custom PHP framework. **Driftex** is the trading bot application built on it.
PHP 8.3+ / MySQL 8.0 / Redis 7.2 / Kafka. No external PHP framework (Laravel, Symfony, etc.).

## Common Commands

```bash
# Start local environment
cd docker && docker compose up -d

# Enter main container
docker exec -it apache bash

# Install Composer dependencies (inside container or with volume mounted)
docker exec -it apache composer install -d /var/www/driftex/site/app/inc/lib

# Run migrations manually
docker exec -it apache php /var/www/driftex/site/cgi-bin/run_migrations.php

# Clear Redis cache
# Via browser: http://driftex.local/clear_cache.php
# Via CLI: docker exec -it apache php -r "require '/var/www/driftex/site/app/inc/main.php'; RedisCache::getInstance()->flush();"

# Tail application logs
docker exec -it apache tail -f /var/log/driftex/migrations.log
```

## Setup

1. Copy `site/app/inc/kernel.php.example` → `site/app/inc/kernel.php` and fill in credentials
2. Copy `docker/docker-compose.yml.example` → `docker/docker-compose.yml` (already exists)
3. `cd docker && docker compose up -d`
4. Run migrations via CLI or `http://driftex.local/migrations.php`
5. Local host: `driftex.local` (add to `/etc/hosts` → `172.29.0.3`)

## Architecture

### Request Flow

```
public_html/index.php
  → main.php (loads kernel.php, autoload, urls.php)
  → dispatcher (routes matched by regex pattern)
  → Controller::method($params)
  → DOLModel (ORM layer)
  → View: ui/page/*.php
```

### Key layers

| Path | Role |
|------|------|
| `site/app/inc/kernel.php` | Constants: DB, Redis, Kafka, session key, paths |
| `site/app/inc/lib/dispatcher.php` | URL router — regex-based, maps to `Controller:method` |
| `site/app/inc/lib/DOLModel.php` | Base ORM: wraps `local_pdo`, handles SELECT/INSERT/UPDATE with Redis cache invalidation |
| `site/app/inc/lib/rootOBJ.php` | Base class with magic `get_*/set_*` via `__call` |
| `site/app/inc/controller/` | Controllers extend nothing (use `rootOBJ` indirectly); loaded by dispatcher |
| `site/app/inc/model/` | Models extend `DOLModel`, one per table |
| `site/public_html/ui/page/` | PHP view templates |
| `site/public_html/assets/js/alpine/` | Alpine.js controllers for each page |
| `site/cgi-bin/` | Background workers (email via Kafka, migrations) |
| `migrations/` | Sequential SQL files: `NNN_description.sql` |

### DOLModel pattern

Models set `$this->filter`, `$this->field`, `$this->paginate`, then call `load_data()`. Soft-delete via `active = 'yes'/'no'`. All tables have `idx` (PK), `created_at`, `modified_at`, `removed_at`, `created_by`, `modified_by`, `removed_by`.

### Routing

Routes defined in `index.php` via `$dispatcher->add_route($method, $regex, "ClassName:method", $authCheck, $params)`. Auth guard: `fn() => auth_controller::check_login()`.

### Cron / Workers

Only the `apache` container runs cron (`ENABLE_CRON=true`). Dedicated workers use `ENABLE_CRON=false`. Kafka topic `driftex_site_emails` handles async email via `kafka_email_worker.php`.

## Migrations

New file: `migrations/NNN_description.sql` (sequential number). Auto-executed by cron every 5 min. Tracked in `migrations_log` table. Never re-executes a successful migration.

## Conventions

- Commits in PT-BR following Conventional Commits (`feat:`, `fix:`, `chore:`, etc.)
- Soft-delete only — never `DELETE` from tables; use `active = 'no'`
- Binance API config read from DB (table `settings`), not from `kernel.php`
- Redis prefix: `driftex:site:` — cache keys pattern `query:<table>:<md5>`
- Session key constant: `cAppKey` = `driftex_site_session`
