# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Gracehubtels — a Nigerian fintech/VTU platform (wallet funding, airtime/data/cable TV/electricity/exam-pin purchases, bank transfers, virtual cards, airtime-to-cash) built as plain PHP (no framework, no Composer autoloading — PHPMailer is vendored manually under `vendor/PHPMailer`), served by Apache/XAMPP with a MySQL backend.

## Running / developing

There is no build step, package manager, linter, or automated test suite in this repo (no `composer.json`, `package.json`, or `tests/` directory) — verify manually in-browser via XAMPP rather than assuming any CLI check exists.

- Serve the app through Apache (XAMPP), not `php -S` — auth/CORS code depends on `apache_request_headers()` and `.htaccess` rewrite rules.
- Database: import `database/msjpay.sql` first (base schema — a structure-only export of the live schema, regenerated periodically, so it already includes whatever migrations were live at export time; check its own header comment for exactly which `migration_vNN` it's current through), then apply only `database/migration_vNN.sql` files numbered **higher than that** — there's no migration runner, they're plain sequential SQL files, and re-applying one already baked into `msjpay.sql` fails loudly with "duplicate column" errors rather than silently corrupting anything.
- DB credentials and app config live as `define()` constants at the top of `user/core/db.php` (not `.env`/`.ini`), and further runtime config (SMTP, provider secrets, cashback rules, notification toggles) lives in the `site_settings` table, read via `setting()`/`setting_set()`.
- `.htaccess` rewrites extensionless URLs to their `.php` file (e.g. `/user/login` → `user/login.php`), except under `/api/`, `/mobile_api/`, `/chatbot/`, `/webhook/`, which are excluded so POST bodies aren't disturbed. Keep this in mind when adding links/redirects.
- `/scratch` holds one-off debug/patch/recovery scripts from prior incidents — not live code paths, don't treat them as reference for how the app should work.

## Architecture

Four top-level surfaces, each with its own auth model, sharing one core library:

- **Public site** — `index.php`, `contact.php`, `terms.php`, `privacy-policy.php`.
- **User portal** (`/user`) — customer dashboard. `user/core/*.php` holds one module per service (`airtime.php`, `data.php`, `cabletv.php`, `electricity.php`, `betting.php`, `virtual_card.php`, `kyc.php`, `bank_transfer.php`, ...). `user/api/v1/*` are internal AJAX endpoints for the dashboard UI (session-authenticated, distinct from the public API below).
- **Admin back office** — currently `/grace-hub-tels` (mid-rename from the deleted `/msj-admin`; if you find references to `msj-admin` they're stale). Session cookie, auth, and RBAC are separate from the user portal (`grace-hub-tels/core/auth.php`, session name `gracehubtels_admin`), with roles `support < admin < superadmin` plus a granular JSON permissions list, device-fingerprint OTP 2FA on new-device login, and `audit_log()` on privileged actions.
- **Public partner API** (`/api/v1`) — external developer API. Auth is a `Bearer msj_live_<token>` header checked against `users.api_token` (`api/v1/auth.php`), independent of both session systems above.
- **Webhooks** (`/webhook/{provider}/index.php`) — inbound callbacks per payment/VTU provider (autopilot, cashwyre, moniepoint, palmpay, paymentpoint, payvessel, vtu). Each verifies its own signature scheme using secrets pulled from `site_settings`.

### Shared core (`user/core/db.php`)

Required by nearly every entry point across all four surfaces (admin's `core/db.php` just re-requires it). It provides:

- `db()` — a lazy PDO singleton (exceptions on error, `FETCH_ASSOC` default).
- Wallet ops: `debit_wallet()` / `credit_wallet()` — always run inside a transaction, `SELECT ... FOR UPDATE` to lock the user row, write a matching `transactions` row with `balance_before`/`balance_after`, and generate a `MSJ...` reference via `generate_ref()`. `refund_failed_transaction()` reverses a failed debit. Any new money-movement code should follow this same lock-then-record pattern rather than updating `wallet_balance` directly.
- `check_transaction_limits()` / `check_daily_limit()` — per-category and global daily spend caps, with per-user overrides (`custom_daily_limit`, `custom_airtime_limit`, `custom_transfer_limit`) taking precedence over `site_settings` defaults.
- Notification fan-out: `notify_user()` writes to `notifications`, emails via `send_email()` (PHPMailer if vendored, else `mail()` fallback), and pushes via OneSignal (`user/core/onesignal.php`) if the user opted in — plus `notify_admin()` / `send_whatsapp_alert()` (Green API) for staff-facing alerts on logins, failed transactions, withdrawals, etc.
- Cashback engine: `apply_cashback()` — checks per-plan cashback config on `data_plans` first, then falls back to the global `cashback_config` table.
- Logging: per-channel flat files in `/logs`, one file per channel (`log_write()`/`log_error()`/`log_info()`/`log_transaction()`/`log_security()`/`log_api()`). `log_api()` redacts sensitive fields (pin/password/token/secret/key/cvv) before writing. When adding a new external API integration, register its channel name in the `$log_channels` array in `db.php` so the log file gets created.
- Security helpers: `check_blocked_ip()`, `detect_tamper()` (pattern-matches SQLi/XSS/RCE-ish strings in POST bodies), `rate_limit()` (file-based, keyed by IP).

### Multi-provider failover (`user/core/api_router.php`)

VTU services (airtime, data, cabletv, electricity, exam_pin, etc.) aren't hardwired to one upstream API — `get_api_providers($service, $network, $data_type)` reads active providers from `api_providers` (optionally joined to `api_registry` for shared credentials), ordered by priority. `try_api_providers($service, $call_fn, ...)` calls each in order via a callback until one succeeds (`is_api_response_failure()` / `is_api_response_pending()` interpret the varied response shapes different providers return). `plan_api_bindings` lets specific data/cable plans pin themselves to a specific provider, overriding the general priority order. Network name→ID mappings differ per provider and are resolved via `build_network_map()`/`build_value_map()`, with provider-specific overrides stored as JSON on the provider row.

### Timezone handling

The app renders times in Africa/Lagos but the DB column may store UTC or local time depending on server config — `db_timezone_is_utc()` detects which, and `fmt_tx_time()` should be used (not raw `date()`/`strtotime()`) whenever displaying a stored `transactions`/timestamp value, to avoid double-converting.
