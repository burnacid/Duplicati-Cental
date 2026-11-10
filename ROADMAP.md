# Roadmap

Where Duplicati Central is heading. These are plans, not promises: order and scope can change.
When an item ships it is removed here and recorded in [CHANGELOG.md](CHANGELOG.md).

Current version: see [VERSION](VERSION).

## v0.6 – Remote control depth

Done, waiting for release (see Unreleased in the changelog).

## v1.0 – Stable

- **Stable API and reporting endpoint** with a backwards-compatibility promise.
- **Supported upgrades** from any 0.x release.
- **Documentation** – install, reverse proxy, HTTPS and passkeys, remote control, troubleshooting.
- **Security** – review of the remote-control protocol and API, and a `SECURITY.md` with how to report issues.
- **Test coverage** for the core services (health, notifications, remote control).
- **Translations** – English first, Dutch next.

## Ideas (not scheduled)

- Teams or organisations with roles (viewer, admin), an audit log and single sign-on (OIDC).
- Multi-architecture Docker image (arm64 for Raspberry Pi and NAS devices).
- Push notifications through the installed app (Web Push).
- Storage cost estimate per destination.
- Home Assistant integration.
- Remote control: edit the full job from Central, export and import job definitions, create new jobs.
- Remote control: delete old versions on demand (Duplicati applies the retention after every backup; there is no separate API call for it yet).

Have a suggestion? Open an issue on [GitHub](https://github.com/burnacid/Duplicati-Cental/issues).
