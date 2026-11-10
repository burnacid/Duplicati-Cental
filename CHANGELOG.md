# Changelog

All notable changes to Duplicati Central are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

Add new entries under **Unreleased**. `release.ps1` turns that section into the next version when publishing.

## [Unreleased]

## [0.3.0] - 2026-11-10

### Added
- Duplicati Central can be installed as an app (PWA) on desktop and mobile. It shows an offline page when the server cannot be reached; backup data is never cached on the device. Installing needs HTTPS.
- "Install app" in the sidebar when the browser supports installing (with Add to Home Screen steps on iPhone and iPad). The browser tab and the installed app icon show how many jobs need attention.
- Daily or weekly summary per notification channel: runs, failures, overdue and snoozed jobs, and total backup size. Sent at `BACKUP_DIGEST_TIME` (default 08:00; weekly on Mondays).
- Snooze alerts for a server (Backup Servers page) or a single job (Backup Management → Settings) for 1 hour up to "until I resume". No result or overdue alerts are sent while snoozed.
- Job settings on Backup Management: set the expected backup interval by hand, or turn off missed-backup checks for jobs that run by hand.
- Size trend chart on Backup Results when one job is selected: source and backup size over the last 30, 90 or 365 days.
- New notification channel types: Slack, Microsoft Teams (Workflows webhook), Telegram, ntfy and Pushover.

### Changed
- New logo and favicons. The logo switches to light colours in dark mode.
- Remote Control shows the name of the linked backup server instead of the machine name, so renaming a server also renames it there. The machine name is still shown next to it, and search matches both.

## [0.2.1] - 2026-11-10

### Fixed
- Duplicati 2.4 instances did not come online in Remote Control. The server now supports protocol version 2, which 2.4 requires, including its end-to-end encrypted commands; 2.3 and older keep working on version 1.

## [0.2.0] - 2026-11-10

### Added
- First admin for Docker installs via `ADMIN_EMAIL` / `ADMIN_PASSWORD` (or `ADMIN_PASSWORD_FILE`), created at start-up when no users exist, so nobody else can claim a fresh installation.
- `php artisan user:create` and `php artisan user:reset-password` (optionally `--disable-2fa`) for creating accounts and recovering locked-out accounts.
- `docker-compose.yml` and `.env.docker.example` with all settings explained, plus optional automatic HTTPS and MySQL/MariaDB setups.

### Changed
- Each Duplicati instance gets the websocket, certificates and reporting URLs on the address it registered with, e.g. the public domain behind a reverse proxy or a LAN address. `REMOTE_CONTROL_BRIDGE_URL` still overrides the websocket for all instances.
- The Remote Control page shows both the public registration URL and the address it is opened on, when they differ.

### Fixed
- A claimed instance got a reporting URL based on the address the claim page was opened on, which could be unreachable for that instance.

## [0.1.0] - 2026-11-09

### Added
- Dashboard with status tiles (healthy, warning, failed, overdue), per-server 30-day overview, per-job history of the last 30 runs and a "Needs attention" list.
- Overdue detection: each job's schedule is learned from its history; an hourly check notifies once per missed period.
- Notification channels (email with optional own SMTP server, Discord, generic webhook) with per-channel level (every result, warnings and errors, errors only), overdue alerts, pause switch and test button.
- Backup results page with filters for server, job, status, operation and name; filters are kept in the URL.
- Backup detail page with log lines, backend statistics and restore details.
- Backup servers: copyable reporting URL and ready-to-paste Duplicati options, API key regeneration, last report time.
- Remote control for Duplicati instances: registration and claim flow, online status, live progress, run backup now, repair, and task status follow-up.
- Import of the last 30 days of results from a connected instance's local logs, skipping results that are already stored (`php artisan remote-control:import-logs`).
- `REMOTE_CONTROL_ENABLED` switch to turn all remote-control features off.
- `REGISTRATION_ENABLED` setting: after the first account, sign-up is closed by default.
- Two-factor authentication with an authenticator app (TOTP), including QR setup, recovery codes and protection against code reuse (Settings → Security).
- Passkeys: passwordless sign-in with fingerprint, face, device PIN or security key (requires HTTPS).
- `REQUIRE_2FA` setting to make an authenticator app or passkey mandatory for all users.
- Daily retention clean-up of old results and remote-control commands (`BACKUP_RETENTION_DAYS`).
- Versioning: `VERSION` file, version shown in the sidebar, release script `release.ps1` publishing to Docker Hub with a git tag and GitHub release.

### Changed
- Docker image: generates and keeps its own `APP_KEY`, runs queue worker and scheduler, installs without dev dependencies, includes the MySQL driver, and keeps data in the `/app/storage` volume.
- Docker image now uses FrankenPHP (Caddy) instead of `php artisan serve`: concurrent requests, OPcache, compression, long-term caching of assets, security headers and a health check. Image size halved (about 1 GB).
- The remote-control websocket is also served on port 8181 (`/api/remote-control/ws`); port 8090 still works.
- Optional automatic HTTPS: set `SERVER_NAME` to your domain and publish ports 80 and 443.
- SQLite database moved to `/app/storage/database.sqlite` (inside the volume); an existing database at the old location is moved on start.
- Logs rotate daily and are kept for 14 days.
- `TRUSTED_PROXIES` setting for running behind a reverse proxy.
- Dependencies updated (Laravel 12.71, Flux 2.21, Vite 6.4, Tailwind 4.3); no known security advisories remain.
- Faster pages with large histories: log lines are stored in their own table and the dashboard loads in a few queries.
- User interface moved to Flux components with consistent status colours; Error and Fatal are both shown as failures.

### Fixed
- Users could see and change other users' servers, API keys and results.
- Error results were sometimes shown as green.
- Backup results for Compact, Repair, Delete and Vacuum were rejected.
- Durations of one day or longer were shown incorrectly.
- Timestamps with a timezone offset were stored incorrectly.
- Delete buttons for servers and backup history did not respond.

### Security
- Removed the shared `APP_KEY` that was built into the Docker image.
- Removed an unauthenticated remote-control claim endpoint.
- Notification channel settings (SMTP passwords, webhook headers) are encrypted in the database.
- The Docker build no longer includes local secrets, keys, logs or `.env` files.

[Unreleased]: https://github.com/burnacid/Duplicati-Cental/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/burnacid/Duplicati-Cental/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/burnacid/Duplicati-Cental/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/burnacid/Duplicati-Cental/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/burnacid/Duplicati-Cental/releases/tag/v0.1.0
