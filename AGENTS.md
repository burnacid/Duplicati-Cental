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
                    └─ queues SendBackupResultNotification → NotificationService::notifyBackupResult
Scheduler (routes/console.php)
  ├─ backups:check-overdue (hourly) → NotificationService::notifyOverdue
  └─ daily pruning (retention settings in config/backups.php)
```

- **Web routes** (`routes/web.php`): all UI pages are full Livewire components (`App\Livewire\*`), including `NotificationChannels\Index`.
- **API routes** (`routes/api.php`): unauthenticated; backup results authenticate via the `{apiKey}` path segment. Remote-control machines are claimed only through the authenticated web flow (`RemoteControlClaimController`).
- **Remote control switch**: `REMOTE_CONTROL_ENABLED` (`config('remote_control.enabled')`, default true). Remote-control routes use the `remote-control` middleware (404 when off); shared screens check the config before showing remote-control UI. Guard any new remote-control feature the same way.
- **History import**: `RemoteLogImportService` pages through `GET /api/v1/backup/{id}/log` on a connected instance and stores `Result` entries as `BackupResult` (`source = 'remote-log'`, no notifications). Duplicates are matched on server + job (`DB-<id>`) + `BeginTime` (±2 s). Started from the machine page (`ImportRemoteControlLogs` job, status in cache) or `php artisan remote-control:import-logs {registration?} --days=30`. Both reports and imports go through `BackupResultFactory`.
- **Docker**: multi-stage build (composer → node → `dunglas/frankenphp`). supervisord runs FrankenPHP (`docker/Caddyfile`: app on `:8181`, `/api/remote-control/ws` proxied to the websocket server on 8090, optional auto-HTTPS via `SERVER_NAME`), the remote-control websocket, `queue:work` and `schedule:work`. `entrypoint.sh` creates the storage layout, generates/persists `APP_KEY`, moves a legacy SQLite file into `/app/storage/database.sqlite`, runs `php artisan optimize` and migrations. Because config is cached in the container, never call `env()` outside `config/*.php`.
- Container files must keep LF line endings (`.gitattributes`).

## Key Models & Conventions

| Model | PK | Notes |
|---|---|---|
| `User` | UUID (`HasUuids`) | Has many `NotificationChannel`. `User::registrationOpen()` gates sign-up (`REGISTRATION_ENABLED`; the first account is always allowed). |
| `BackupServer` | UUID | `api_key` (64-char random) auto-set in `boot()`, hidden from serialisation. `scopeOwnedBy()` |
| `BackupResult` | Auto-int | CamelCase column names mirror Duplicati's JSON schema. `scopeForUser()`, `status` accessor |
| `NotificationChannel` | Auto-int | `config` is `encrypted:array`; `notify_on` (all/warning/error), `notify_overdue`; `type` validated against `config/notification_channels.php` |

- **Always scope queries to the current user**: `BackupServer::ownedBy($user)`, `BackupResult::forUser($user)`, `auth()->user()->notificationChannels()`.
- `BackupResult.backup_name` / `backup_id` are extracted from `extra['backup-name']` / `extra['backup-id']` in `boot()`.
- `EndTime` / `BeginTime` are stored as naive UTC strings via the `App\Casts\UtcDateTime` cast and returned in `config('app.timezone')`. Raw SQL on these columns compares UTC values.
- **Status colours**: use `App\Enums\BackupStatus` (`fromResult()`, `worstOf()`, `color()`, `fluxColor()`, `isFailure()`); `Error` and `Fatal` are both failures. Blade: `<x-backup-status-badge :status="..." />` and `<x-status-bar :items="..." />`.
- Dashboard data (per-job history, day statuses, overdue detection) comes from `App\Services\BackupHealthService`.

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
- Release with `./release.ps1` (PowerShell 7) from a clean, pushed `master`: first release `./release.ps1`, later `./release.ps1 -Bump patch|minor|major`. Use `-DryRun` to build without publishing. It runs the tests, moves the Unreleased notes into the new version, commits `Release vX.Y.Z`, tags `vX.Y.Z`, pushes the image `burnacid/duplicati-central:{X.Y.Z, X.Y, latest}` to Docker Hub, pushes git and creates the GitHub release. Needs `docker login` and `gh auth login`.
- `.dockerignore` keeps secrets out of the public image (`.env`, `storage/app/*` incl. remote-control keys, logs, sessions). Keep it that way when adding files with secrets.

## Testing
- Feature tests use `RefreshDatabase` (configured globally in `tests/Pest.php`), which empties the database. `Tests\TestCase::createApplication()` refuses to run unless the database is in-memory SQLite and config is not cached. Never run `php artisan optimize` / `config:cache` on a development machine; use `php artisan optimize:clear` if it happened.
- `phpunit.xml` forces the test environment (`force="true"`), including feature switches, so a local `.env` cannot change test behaviour.
- Run a single test file: `php artisan test --filter=YourTestName` from `app/`.
- `tests/Feature/UserIsolationTest.php` checks that users cannot see or change each other's data. Add a case there for every new user-owned page.
- On Windows, the two websocket/roundtrip tests fail unless PHP can find `openssl.cnf` (RSA key generation); they pass in Docker.

