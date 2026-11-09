# Changelog

All notable changes to Duplicati Central are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

Add new entries under **Unreleased**. `release.ps1` turns that section into the next version when publishing.

## [Unreleased]

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

[Unreleased]: https://github.com/burnacid/Duplicati-Cental/commits/master
