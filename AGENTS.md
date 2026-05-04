# AGENTS.md

High-signal guidance for OpenCode sessions working in this repository.

## Project Identity

- **NexoFW** = custom PHP framework (no Laravel/Symfony).
- **Driftex** = trading-bot application built on it.
- PHP 8.4+ / MySQL 8.0 / Redis 7.2 / Kafka.

## Local Environment

```bash
# Start services
cd docker && docker compose up -d

# Main container shell
docker exec -it apache bash

# Install dependencies (vendor path is NOT at repo root)
docker exec -it apache composer install -d /var/www/driftex/site/app/inc/lib

# Run migrations manually
docker exec -it apache php /var/www/driftex/site/cgi-bin/run_migrations.php

# Tail application logs
docker exec -it apache tail -f /var/log/driftex/migrations.log
```

- Local host: `driftex.local` → `172.29.0.3` (add to `/etc/hosts`).
- Access: `http://driftex.local/migrations.php` for migration UI.

## Setup Gotcha

`site/app/inc/kernel.php` is **gitignored** but exists in the working tree. It contains live credentials. Do **not** commit it. There is currently no `kernel.php.example` file in the repo.

## Request Flow

```
public_html/index.php
  → main.php (loads kernel.php, autoload, urls.php)
  → dispatcher (regex routes)
  → Controller::method($params)
  → DOLModel
  → View: ui/page/*.php
```

## Architecture Notes

| Path | Role |
|------|------|
| `site/app/inc/kernel.php` | DB, Redis, Kafka, SMTP, session constants |
| `site/app/inc/lib/dispatcher.php` | Regex router; maps `"ClassName:method"` |
| `site/app/inc/lib/DOLModel.php` | Base ORM: wraps `local_pdo`, handles SELECT/INSERT/UPDATE with Redis cache invalidation |
| `site/app/inc/lib/rootOBJ.php` | Magic `get_*/set_*` via `__call` |
| `site/app/inc/controller/` | Plain PHP classes (extend nothing); loaded by dispatcher |
| `site/app/inc/model/` | Extend `DOLModel`, one per table |
| `site/public_html/ui/page/` | PHP view templates (plain `include`, no engine) |
| `site/public_html/assets/js/alpine/` | Alpine.js controllers |
| `site/cgi-bin/` | Background workers (Kafka email worker, migration runner, verify_entry) |

### Routing

```php
$dispatcher->add_route("GET", "/config(\.json|\.xml|\.html)?", "config_controller:display", $authGuard, $params);
```

- Auth guard pattern: `$authGuard = fn() => auth_controller::check_login();`
- Routes support `"function:name"` or `"Class:method"`.

### DOLModel Pattern

Models configure queries by setting properties, then call `load_data()`:

```php
$model = new grids_model();
$model->set_filter(["active = 'yes'", "symbol = 'BTCUSDC'"]);
$model->load_data();
```

- **Soft-delete only.** Never `DELETE`; use `active = 'yes'/'no'`.
- All tables have: `idx` (PK), `created_at`, `modified_at`, `removed_at`, `created_by`, `modified_by`, `removed_by`.
- `save()` auto-sets `created_at`/`created_by` on insert, `modified_at`/`modified_by` on update.
- `remove()` does a soft delete (sets `active = 'no'`, `removed_at`, `removed_by`).
- Cache invalidation is automatic on `save()` / `remove()` via Redis pattern `query:<table>:*`.

### Redis

- Prefix: `driftex:site:`
- Cache key pattern: `query:<table>:<md5>`
- Session key constant: `cAppKey` = `driftex_site_session`

## Migrations

- Location: `migrations/NNN_description.sql` (sequential number).
- Auto-executed by cron every 5 min; tracked in `migrations_log` table.
- Never re-executes a successful migration.
- Create new file → wait for cron, or run manually via CLI/web UI.

## Cron / Workers

Critical rule: **only the `apache` container runs cron** (`ENABLE_CRON=true`).
Dedicated workers must set `ENABLE_CRON=false`.

- Kafka topic `driftex_site_emails` handles async email via `kafka_email_worker.php`.
- `verify_entry.php` is the cron-driven entry point for bot operations.

## Conventions

- Commits in **PT-BR** following Conventional Commits (`feat:`, `fix:`, `chore:`, etc.).
- Binance API credentials are read from the DB (`settings` table), **not** from `kernel.php`.
- No test runner or linter is configured in this repo.
