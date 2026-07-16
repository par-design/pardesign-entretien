# PAR Design — Entretien

Private WordPress plugin that connects a site to the central **PAR Design** backend to automate and track maintenance visits (WordPress core and plugin updates, database backups, server audits, client reports).

## How it works

A **maintenance visit** (*entretien*) captures a snapshot of the site's state (core version, list and versions of installed plugins) *before* updates are applied, runs the updates, then captures a second snapshot *after*. The PAR Design backend diffs the two snapshots and generates a report (draft, or sent directly to the client).

Three ways to trigger a visit:

- **Manually** from the WordPress admin (Tools → Entretien PAR Design): Start / Finish buttons, with your own updates done in between.
- **Remotely** via the plugin's REST API, driven by the backend (a "Run now" button or a server-side scheduled job).
- **In "pull" mode**: the plugin polls the backend hourly to check whether an automatic visit is due, then runs it itself (useful behind a firewall that blocks inbound calls).

In automatic mode (`run_auto`), the plugin backs up the database, updates plugins (and core if requested), then pushes the report — never sending it to the client if any update failed.

## Features

- **Snapshots** of the WordPress core version and installed plugins (`class-snapshot.php`).
- **Automatic updates** of plugins and/or core, raising PHP limits for long-running operations, with a safety net (`register_shutdown_function`) that captures a draft report if the process dies mid-run (`class-entretien.php`).
- **Database backup** before every automatic update run: a gzipped dump via `mysqldump` when available, otherwise a pure-PHP streaming export (shared hosting) — a backup failure never blocks the visit (`class-backup.php`).
- **Server audit**: PHP/WP version, HTTPS, `WP_DEBUG`, file editing disabled, detected security plugins (`class-server-audit.php`).
- **API client** to the backend, authenticated with a per-site API key (`class-api-client.php`).
- **REST endpoints** (`pardesign-entretien/v1`) driven by the backend, protected by a Bearer token compared in constant time (`class-rest.php`):
  - `POST /run` — schedules a visit in the background (`scope`: `full` | `plugins` | `none`, `send`: bool).
  - `POST /recover` — recovers an interrupted visit (generates the report from the current state).
  - `POST /audit-data` — pushes an on-demand server audit.
  - `POST /self-update` — updates the plugin itself to the latest version published by the backend.
- **Plugin self-update** from the PAR Design backend (outside wordpress.org), via metadata and an archive served at `{backend_url}/plugin/` (`class-updater.php`).
- **Admin page** to configure the backend and drive a manual visit (`class-admin-ui.php`).
- **WP-CLI commands** matching the manual visit workflow from the command line (`class-cli.php`):

  ```
  wp pardesign entretien start
  wp plugin update --all
  wp pardesign entretien finish [--draft]
  wp pardesign entretien status
  wp pardesign entretien cancel
  wp pardesign entretien recover <entretien_id> [--send]
  wp pardesign entretien backup
  ```

## Configuration

Settings are available under **Tools → Entretien PAR Design**, or as constants in `wp-config.php` (handy to avoid storing the API key in the database):

| Setting | Constant |
|---|---|
| Backend URL | `PARDESIGN_ENTRETIEN_BACKEND_URL` |
| Site API key | `PARDESIGN_ENTRETIEN_API_KEY` |
| Site identifier | `PARDESIGN_ENTRETIEN_SITE_ID` |
| ClickUp task ID | `PARDESIGN_ENTRETIEN_CLICKUP_TASK_ID` |
| ClickUp email field ID | `PARDESIGN_ENTRETIEN_CLICKUP_EMAIL_FIELD_ID` |

The default backend URL is `https://entretiens.pardesign.net`. The default site identifier is the site's hostname.

## Requirements

- WordPress ≥ 5.8
- PHP ≥ 7.4

## Architecture

```
pardesign-entretien.php        Plugin bootstrap
includes/
  class-settings.php           Configuration (options + wp-config constants)
  class-snapshot.php           Version-state capture (core + plugins)
  class-server-audit.php       Server audit data collection
  class-backup.php             Database backup (mysqldump or PHP dump)
  class-api-client.php         HTTP client to the PAR Design backend
  class-entretien.php          Visit orchestration (manual + automatic)
  class-rest.php               REST endpoints driven by the backend
  class-admin-ui.php           Admin page
  class-updater.php            Plugin self-update from the backend
  class-cli.php                WP-CLI commands
```
