# Duplicati Central – Agent Guide

## What This Project Is
A self-hosted Laravel 12 dashboard that aggregates backup results from one or more [Duplicati](https://www.duplicati.com/) instances. Duplicati posts JSON payloads to a per-server API endpoint; the app stores, displays, and notifies on those results.

## Tech Stack
- **PHP 8.2+ / Laravel 12**, **Livewire (Flux) + Volt**, **Tailwind CSS v4**, **Pest** (testing), **SQLite** (default in Docker)
- All app code lives under `app/` (the Laravel root); the repo root contains `Dockerfile` and `entrypoint.sh`.

## Developer Workflows
```bash
# From app/
composer run dev          # starts Laravel server + queue worker + Vite concurrently
composer run test         # php artisan config:clear && php artisan test (Pest)
php artisan migrate       # run migrations manually
```
- **Vite** (`vite.config.js`) has a hardcoded dev server host (`192.168.1.200`). Change `server.host` and `server.hmr.host` to your local IP before running `npm run dev`.
- **Docker**: `entrypoint.sh` runs `npm run build` + `php artisan migrate --force` automatically on start. The container exposes port **8181**.

## Architecture & Data Flow

```
Duplicati instance
  └─ POST /api/backup-servers/{serverId}/{apiKey}/backup-result
        └─ BackupController::storeBackupResult (validates first, skips List* operations)
              └─ creates BackupResult (scoped to BackupServer)
                    ├─ queues SendBackupResultNotification → NotificationService::notifyBackupResult
                    │     + BackupAlertRuleService::check → NotificationService::notifyAlerts (per-job rules)
                    └─ queues PingHeartbeat (successful backups of jobs with a heartbeat_url)
Scheduler (routes/console.php)
  ├─ backups:check-overdue (hourly) → NotificationService::notifyOverdue (skips snoozed jobs)
  ├─ backups:send-digest daily|weekly (BACKUP_DIGEST_TIME) → DigestService::build → NotificationService::notifyDigest
  ├─ daily pruning (retention settings in config/backups.php)
  ├─ heartbeats every minute (scheduler) / 5 min (QueueHeartbeat job) → SystemHealthService
  └─ app:backup daily when CENTRAL_BACKUP_ENABLED → CentralBackupService
```

- **Web routes** (`routes/web.php`): all UI pages are full Livewire components (`App\Livewire\*`), including `NotificationChannels\Index`.
- **API routes** (`routes/api.php`): backup results authenticate via the `{apiKey}` path segment. The read-only REST API and Prometheus metrics live under `/api/v1` (`Api\V1\ReadApiController`, `MetricsController` → `App\Services\MetricsService`) behind `auth:sanctum`, `abilities:read` and `throttle:api`. Tokens are created in Settings → API tokens (`App\Livewire\Settings\ApiTokens`, behind `password.confirm`). `config/sanctum.php` has `guard => []`, so the session never authenticates the API; API errors are always JSON (`bootstrap/app.php`). Keep new endpoints read-only unless a new ability is added, and scope them to `$request->user()`. Remote-control machines are claimed only through the authenticated web flow (`RemoteControlClaimController`).
- **Remote control switch**: `REMOTE_CONTROL_ENABLED` (`config('remote_control.enabled')`, default true). Remote-control routes use the `remote-control` middleware (404 when off); shared screens check the config before showing remote-control UI. Guard any new remote-control feature the same way.
- **Remote-control protocol**: the welcome offers versions 1 and 2; the client's `protocolVersion` from auth is kept per connection. Duplicati 2.4+ (v2) refuses servers without version 2 and requires command payloads as `{"v":2,"jwe":...}` (RSA-OAEP-256 + A256GCM, gzip above 2 KiB, `zip:"gzip"`), with plaintext `{messageId, replyKey, request}`; replies are encrypted to `replyKey` (our own server key). See `RemoteControlCryptoService::encryptCommandRequest()` / `decryptCommandResponse()`.
- **History import**: `RemoteLogImportService` pages through `GET /api/v1/backup/{id}/log` on a connected instance and stores `Result` entries as `BackupResult` (`source = 'remote-log'`, no notifications). Duplicates are matched on server + job (`DB-<id>`) + `BeginTime` (±2 s). Started from the machine page (`ImportRemoteControlLogs` job, status in cache) or `php artisan remote-control:import-logs {registration?} --days=30`. Both reports and imports go through `BackupResultFactory`.
- **Docker**: multi-stage build (composer → node → `dunglas/frankenphp`). supervisord runs FrankenPHP (`docker/Caddyfile`: app on `:8181`, `/api/remote-control/ws` proxied to the websocket server on 8090, optional auto-HTTPS via `SERVER_NAME`), the remote-control websocket, `queue:work` and `schedule:work`. `entrypoint.sh` creates the storage layout, generates/persists `APP_KEY`, moves a legacy SQLite file into `/app/storage/database.sqlite`, runs `php artisan optimize` and migrations. Because config is cached in the container, never call `env()` outside `config/*.php`.
- Container files must keep LF line endings (`.gitattributes`).

## Key Models & Conventions

| Model | PK | Notes |
|---|---|---|
| `User` | UUID (`HasUuids`) | Has many `NotificationChannel`. `User::registrationOpen()` gates sign-up (`REGISTRATION_ENABLED`; the first account is always allowed). |
| `BackupServer` | UUID | `api_key` (64-char random) auto-set in `boot()`, hidden from serialisation. `scopeOwnedBy()` |
| `BackupResult` | Auto-int | CamelCase column names mirror Duplicati's JSON schema. `scopeForUser()`, `status` accessor |
| `NotificationChannel` | Auto-int | `config` is `encrypted:array`; `notify_on` (all/warning/error), `notify_overdue`, `digest` (off/daily/weekly); `type` validated against `config/notification_channels.php` |
| `BackupJobSetting` | Auto-int | Per job (`backup_server_id` + `backup_id`): `expected_interval_hours` (manual overdue interval), `monitored`, `muted_until`, alert rules (`alert_*`, null = off), `heartbeat_url` (encrypted), `on_status_page` / `status_page_name`. Reach it through an owned `BackupServer` (`jobSettings()`). |

- **Snooze**: `BackupServer.muted_until` and `BackupJobSetting.muted_until` ("until I resume" = +100 years). Check with `BackupServer::isJobMuted($backupId)`; durations and labels in `App\Support\Snooze`. Snoozed jobs get no result, overdue or badge alerts and are listed separately in the digest.

- **Always scope queries to the current user**: `BackupServer::ownedBy($user)`, `BackupResult::forUser($user)`, `auth()->user()->notificationChannels()`.
- `BackupResult.backup_name` / `backup_id` are extracted from `extra['backup-name']` / `extra['backup-id']` in `boot()`.
- `EndTime` / `BeginTime` are stored as naive UTC strings via the `App\Casts\UtcDateTime` cast and returned in `config('app.timezone')`. Raw SQL on these columns compares UTC values.
- **Status colours**: use `App\Enums\BackupStatus` (`fromResult()`, `worstOf()`, `color()`, `fluxColor()`, `isFailure()`); `Error` and `Fatal` are both failures. Blade: `<x-backup-status-badge :status="..." />` and `<x-status-bar :items="..." />`.
- Dashboard data (per-job history, day statuses, overdue detection) comes from `App\Services\BackupHealthService`. Each job also carries `monitored`, `is_muted`, `muted_until` and `interval_source` (manual/learned/fallback); a manual interval is overdue after one missed run plus the grace period. Show the flags with `<x-job-flags :job="$job" />`.
- **PWA**: `public/site.webmanifest`, `public/sw.js` (caches only `/build/*` and icons; pages are never cached), `public/offline.html`, `resources/js/pwa.js` (install button store `$store.pwa`, `window.dcSetFailingJobs()` for the tab title / app badge, fed by `App\Livewire\FailingJobsBadge` in the sidebar). Bump `CACHE` in `sw.js` when the precached files change.
- **Size chart**: `<x-size-trend-chart :points="..." />` renders inline SVG from `App\Support\SizeTrendChart` (no JS chart library).
- **Health** (Settings → Health, `App\Livewire\Settings\Health`): `App\Services\SystemHealthService::checks()` returns `{key, label, status (ok|warning|error|off), summary, detail}`. Background processes report with `beat()` (cached timestamps); the websocket server beats every 30 s. It shows installation status only, never user data, so every signed-in user may see it. Add a check there for any new background process.
- **Alert rules** (`App\Services\BackupAlertRuleService`): checked for Backup runs that did not fail; each rule fires only on the run that crosses its limit (no repeats while the condition lasts). Alerts go to channels that want warnings and respect snooze.
- **Public status page**: `/status/{token}` (`StatusPageController`, `throttle:status-page`, no login) with data from `App\Services\StatusPageService`, which exposes only public name, state and backup times. Never add server names, sizes, paths, logs or links to it. `User.status_page_token` (hidden) turns it on; Settings → Status page (`App\Livewire\Settings\StatusPage`) manages it.
- **Self-backup**: `App\Services\CentralBackupService` (`app:backup` / `app:restore`) zips a `VACUUM INTO` snapshot of SQLite, `APP_KEY` and `storage/app/remote-control/*.pem` with a `manifest.json` (format 1, optional AES-256 password) into `config('backups.central.path')` (`storage/backups`). Restore checks everything before writing and keeps the old database as `*.before-restore-*`. Include any new secret file in storage there.

## Adding a New Notification Channel Type
1. `config/notification_channels.php` – add `'type_key' => 'Display Name'` to `available_types`.
2. `app/Services/NotificationService.php` – add an arm in `sendToChannel()` and implement the `send*()` method (throw on failure; `deliver()` logs and continues with other channels).
3. `app/Livewire/NotificationChannels/Index.php` – add validation rules in `rules()`, the allowed config keys in `cleanConfig()`, and the form fields in its view.

## Livewire Component Patterns
- Livewire components live in `app/app/Livewire/` and are namespaced `App\Livewire\`.
- Components with pagination use `WithPagination` and call `$this->resetPage()` when filters change. Bookmarkable filters use `#[Url]`.
- Size/duration formatting: `App\Livewire\Concerns\FormatsBackupValues` (handles .NET `d.hh:mm:ss` durations).
- Use Flux components (`flux:button`, `flux:modal` opened via `Flux::modal()`, `flux:input copyable`, `flux:badge`, `flux:callout`). Pass user-supplied values to `wire:click` with `@js()`, never inside quoted strings.
- Volt single-file components (settings and auth pages) are in `resources/views/livewire/` and registered via `Volt::route()`.

## Authentication (TOTP & passkeys)
- **TOTP**: `TwoFactorAuthenticationService` (pragmarx/google2fa, bacon QR). Secret and recovery codes are encrypted casts on `User`; `two_factor_last_timestep` blocks code reuse. Login (`auth/login` Volt) validates the password, then sends TOTP users to `auth/two-factor-challenge` with `session('login.two_factor')` (5 min).
- **Passkeys**: spatie/laravel-passkeys backend (`Route::passkeys()` in `routes/auth.php`, `HasPasskeys` on `User`), with our own UI: `<x-passkey-login />` on the login page and `App\Livewire\Settings\Passkeys`. `@simplewebauthn/browser` is exposed on `window` in `resources/js/app.js`. Passkeys are passwordless sign-in (they skip TOTP) and need HTTPS; the relying party is the host of `APP_URL` (`PASSKEYS_RP_ID`).
- Accounts from the CLI: `user:create` (interactive, or `--from-env` reading `ADMIN_EMAIL`/`ADMIN_PASSWORD`/`ADMIN_PASSWORD_FILE` via `getenv()`; run by `entrypoint.sh`, only when no users exist) and `user:reset-password {email} [--disable-2fa]`.
- Settings → Security (`settings/security`) is behind `password.confirm`. `REQUIRE_2FA=true` (`two-factor.required` middleware) forces users without TOTP/passkey to that page; settings routes stay reachable.
- Middleware that must also guard Livewire actions is registered with `Livewire::addPersistentMiddleware()` in `AppServiceProvider`.

## Authorization
`BackupServerPolicy` and `NotificationChannelPolicy` enforce ownership and are registered in `AppServiceProvider::boot()`. Call `$this->authorize()` before mutating user-owned resources, and look them up through the user scopes above.

## Versioning & Releases
- `VERSION` (repo root) holds the version (SemVer). The app reads it via `config('app.version')`: `APP_VERSION` env in Docker, otherwise the `VERSION` file. It is shown in the sidebar.
- Record every user-visible change under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog: Added / Changed / Fixed / Security).
- `ROADMAP.md` lists planned work per version. When a change ships a roadmap item, remove it there (it now lives in `CHANGELOG.md`).
- Release with `./release.ps1` (PowerShell 7) from a clean, pushed `master`: first release `./release.ps1`, later `./release.ps1 -Bump patch|minor|major`. Use `-DryRun` to build without publishing. It runs the tests, moves the Unreleased notes into the new version, commits `Release vX.Y.Z`, tags `vX.Y.Z`, pushes the image `burnacid/duplicati-central:{X.Y.Z, X.Y, latest}` to Docker Hub, pushes git and creates the GitHub release. Needs `docker login` and `gh auth login`.
- `.dockerignore` keeps secrets out of the public image (`.env`, `storage/app/*` incl. remote-control keys, logs, sessions). Keep it that way when adding files with secrets.

## Testing
- Feature tests use `RefreshDatabase` (configured globally in `tests/Pest.php`), which empties the database. `Tests\TestCase::createApplication()` refuses to run unless the database is in-memory SQLite and config is not cached. Never run `php artisan optimize` / `config:cache` on a development machine; use `php artisan optimize:clear` if it happened.
- `phpunit.xml` forces the test environment (`force="true"`), including feature switches, so a local `.env` cannot change test behaviour.
- Run a single test file: `php artisan test --filter=YourTestName` from `app/`.
- `tests/Feature/UserIsolationTest.php` checks that users cannot see or change each other's data. Add a case there for every new user-owned page.
- RSA key generation falls back to `resources/openssl.cnf` when PHP has no OpenSSL config (common on Windows), so the full suite runs on Windows too.
- **Upgrade tests**: `tests/Feature/DatabaseUpgradeTest.php` loads frozen databases from `tests/Fixtures/database-*.sql` (pre-v0.1 legacy and v0.1.0) and migrates them to the current schema. Never regenerate these fixtures from today's migrations or edit old migrations; add a new fixture when a release changes data in a migration.
- **Code style**: Pint (Laravel preset). Run `vendor/bin/pint` before committing; CI runs `pint --test`.
- **CI**: `.github/workflows/ci.yml` (repo root) runs Pint, the tests and a Docker build (not pushed) on pushes to `master` and on pull requests.

