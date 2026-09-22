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
- **Database backup** before every automatic update run: a gzipped dump via `mysqldump` when available, otherwise a pure-PHP streaming export (shared hosting) — a backup failure never blocks the visit (`class-backup.php`). Dumps live in `wp-content/uploads/pardesign-entretien-{random token}/` (token stored in an option, directory mode 0700, files 0600, `.htaccess` and `web.config` guards); the three most recent are kept, and all of them are deleted when the plugin is uninstalled (`uninstall.php`).
- **Server audit**: PHP/WP version, HTTPS, `WP_DEBUG`, file editing disabled, detected security plugins (`class-server-audit.php`).
- **API client** to the backend, authenticated with a per-site API key sent as a Bearer token, over HTTPS only (`class-api-client.php`).
- **REST endpoints** (`pardesign-entretien/v1`) driven by the backend. Every inbound request must be signed with the backend's Ed25519 private key and arrive over TLS; see [Inbound request signing](#inbound-request-signing) (`class-rest.php`, `class-inbound-auth.php`):
  - `POST /run` — schedules a visit in the background (`scope`: `full` | `plugins` | `none`, `send`: bool). Answers `409` while another visit is scheduled or running (`class-lock.php`).
  - `POST /recover` — recovers an interrupted visit (generates the report from the current state).
  - `POST /audit-data` — pushes an on-demand server audit.
  - `POST /self-update` — updates the plugin itself to the latest signed GitHub release; the response carries `last_error` when no update could be verified.
  - `POST /rotate-key` — replaces the site's outbound API key (`api_key`, 32–128 chars of `[A-Za-z0-9_-]`), so a leaked key can be closed from the backend. Refused with `409` when the key is defined as a `wp-config.php` constant.
- **Plugin self-update** from signed GitHub Releases through the standard WordPress update mechanism (plugins screen, WP-CLI, `/self-update`): the release manifest is Ed25519-signed with an offline key and the archive hash is verified before install (`class-updater.php`, see [Releases](#releases)).
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

## Inbound request signing

The site's API key only authenticates the site towards the backend. Calls in the other direction (backend → site) are authenticated by an Ed25519 signature, so nothing stored on a site can be used to drive that site: a leaked database or key lets an attacker post fake snapshots to the backend at worst, never trigger updates.

Generate the backend keypair once with `php tools/backend-keygen.php` (run it twice: the second key is a recovery key kept offline). Pin the public keys in `Pardesign_Entretien_Inbound_Auth::TRUSTED_BACKEND_KEYS` (ids `b1-2026`, `b2-2026`) and put the private key in the backend environment. Placeholders fail closed: every inbound request is refused until real keys are pinned. Pull mode keeps working meanwhile, since it only uses outbound calls.

Each request carries four headers:

| Header | Value |
|---|---|
| `X-Pardesign-Key-Id` | id of the signing key, e.g. `b1-2026` |
| `X-Pardesign-Timestamp` | unix time in seconds; accepted within ±300 s of the site clock |
| `X-Pardesign-Nonce` | random, 16–128 chars of `[A-Za-z0-9_-]`, unique per request (single use, remembered 15 min) |
| `X-Pardesign-Signature` | base64 Ed25519 detached signature of the message below |

The signed message is seven lines joined with `\n` and no trailing newline: key id, timestamp, nonce, site id (the value the site sends as `X-Pardesign-Site`), HTTP method in upper case, REST route without the `/wp-json` prefix (e.g. `/pardesign-entretien/v1/run`), and the hex SHA-256 of the raw request body (an empty body hashes to `e3b0c442…b855`). Rejections come back as `401` with a code (`missing_signature`, `untrusted_key`, `stale_timestamp`, `bad_nonce`, `bad_signature`, `replay`) or `403 https_required`.

Node example for the backend (`PARDESIGN_SIGNING_KEY` is the PKCS#8 base64 private key printed by the generator):

```js
import { createPrivateKey, createHash, randomBytes, sign } from 'node:crypto';

const key = createPrivateKey({ key: Buffer.from(process.env.PARDESIGN_SIGNING_KEY, 'base64'), format: 'der', type: 'pkcs8' });

export function signedHeaders(siteId, method, route, body = '') {
  const keyId = process.env.PARDESIGN_SIGNING_KEY_ID; // e.g. "b1-2026"
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = randomBytes(24).toString('base64url');
  const bodyHash = createHash('sha256').update(body, 'utf8').digest('hex');
  const message = [keyId, timestamp, nonce, siteId, method.toUpperCase(), route, bodyHash].join('\n');
  return {
    'X-Pardesign-Key-Id': keyId,
    'X-Pardesign-Timestamp': timestamp,
    'X-Pardesign-Nonce': nonce,
    'X-Pardesign-Signature': sign(null, Buffer.from(message, 'utf8'), key).toString('base64'),
  };
}
// fetch(`${siteUrl}/wp-json/pardesign-entretien/v1/run`, { method: 'POST', body, headers: { 'Content-Type': 'application/json', ...signedHeaders(siteId, 'POST', '/pardesign-entretien/v1/run', body) } })
```

Deployment order: pin the public key in a plugin release first, then switch the backend to signed requests once every site runs that release. Sites still on an older plugin only understand the Bearer header, so the backend can send both during the transition.

Inbound calls require TLS (`is_ssl()`); a site behind a proxy that terminates TLS must set `$_SERVER['HTTPS'] = 'on'` in `wp-config.php` when `X-Forwarded-Proto` is `https`. For local development only, `add_filter( 'pardesign_entretien_allow_insecure_rest', '__return_true' )` disables the check.

## Configuration

Settings are available under **Tools → Entretien PAR Design**, or as constants in `wp-config.php` (handy to avoid storing the API key in the database):

| Setting | Constant |
|---|---|
| Backend URL | `PARDESIGN_ENTRETIEN_BACKEND_URL` |
| Site API key | `PARDESIGN_ENTRETIEN_API_KEY` |
| Site identifier | `PARDESIGN_ENTRETIEN_SITE_ID` |
| ClickUp task ID | `PARDESIGN_ENTRETIEN_CLICKUP_TASK_ID` |
| ClickUp email field ID | `PARDESIGN_ENTRETIEN_CLICKUP_EMAIL_FIELD_ID` |

The default backend URL is `https://entretiens.pardesign.net`; only `https://` URLs are accepted and used. The default site identifier is the site's hostname.

On multisite, settings are network options and the page and endpoints require `manage_network_options`, because the automatic run updates core and plugins for the whole network.

## Releases

Updates are published as GitHub Releases on the public repository `par-design/pardesign-entretien` (tag `v{version}`) with two assets that must keep these exact names.

| Asset | Content |
|---|---|
| `pardesign-entretien.zip` | Plugin archive, top-level folder `pardesign-entretien/` |
| `pardesign-entretien.json` | Signed manifest: `{ key_id, payload, signature }` plus unsigned legacy fields |

The plugin fetches `releases/latest/download/pardesign-entretien.json`, verifies the Ed25519 signature against the public keys pinned in `Pardesign_Entretien_Updater::TRUSTED_KEYS`, then downloads `releases/download/v{version}/pardesign-entretien.zip` and checks its SHA-256 against the signed manifest before WordPress unpacks it. Neither GitHub nor the backend can produce an installable package: only the offline signing key can. Since the version is signed, downgrades are impossible. The releases repository must be readable without authentication (sites carry no GitHub token).

### One-time setup: signing keys

```
php tools/keygen.php
```

Run it twice. Pin both public keys in `TRUSTED_KEYS` (ids `k1-2026`, `k2-2026`). Store the two secret keys offline in two different places (password manager, encrypted file). Never commit them, never put them in CI or hosting environment variables. The second key is the recovery key: if the first one leaks, ship a release signed with the second that removes the first from `TRUSTED_KEYS`. Planned rotation: add the new key, release, remove the old key in a later release. Placeholder values fail closed (no update is ever offered).

### Publishing a release

1. Bump `Version` in `pardesign-entretien.php` and `PARDESIGN_ENTRETIEN_VERSION`, commit, tag `v{version}`.
2. Build and sign from the committed tree (the secret key is read on stdin, never from an argument):

   ```
   tools/build-release.sh k1-2026 7.0 CHANGELOG.md < /path/to/secret-k1.b64
   ```

   This writes `dist/pardesign-entretien.zip` and `dist/pardesign-entretien.json`. The manifest version is read from the plugin header inside the archive, so it can never disagree with the zip.
3. Create the GitHub release on the tagged commit with both files:

   ```
   gh release create v{version} --latest --title v{version} --notes-file CHANGELOG.md \
     dist/pardesign-entretien.zip dist/pardesign-entretien.json
   ```

4. Check that the assets are reachable anonymously (this is exactly what the sites do):

   ```
   curl -sIL https://github.com/par-design/pardesign-entretien/releases/latest/download/pardesign-entretien.json | grep -E '^HTTP'
   ```

Never mark a release as draft or pre-release: `releases/latest` ignores both, so sites would keep the previous version. Never delete a published release either; publish a fixed version instead (downgrades are refused by the updater).

Staging: define `PARDESIGN_ENTRETIEN_UPDATE_REPO` (`owner/repo`) or `PARDESIGN_ENTRETIEN_UPDATE_BASE` (an HTTPS origin mirroring the GitHub layout) in `wp-config.php`. Signing makes the origin a matter of availability only.

### Compatibility with sites still on 0.7.x

Versions up to 0.7.2 read `{backend_url}/plugin/pardesign-entretien.json` (expecting a plain manifest with a top-level `version`) and install `{backend_url}/plugin/pardesign-entretien.zip`, without verification. The signed manifest keeps those legacy top-level fields, so the backend only needs two redirects to migrate old sites:

```
/plugin/pardesign-entretien.json  ->  https://github.com/par-design/pardesign-entretien/releases/latest/download/pardesign-entretien.json
/plugin/pardesign-entretien.zip   ->  https://github.com/par-design/pardesign-entretien/releases/latest/download/pardesign-entretien.zip
```

Old sites then install the latest release through their unverified path and verify everything from the next update on. Right after that upgrade, the old code may leave a stale "update available" entry in the WordPress transient for up to 12 hours; the new updater detects it, clears it, and never reinstalls the same version. Remove the redirects once no site runs 0.7.x anymore.

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
  class-inbound-auth.php       Ed25519 verification of backend → site requests
  class-lock.php               One-run-at-a-time lock
  class-entretien.php          Visit orchestration (manual + automatic)
  class-rest.php               REST endpoints driven by the backend
  class-admin-ui.php           Admin page
  class-updater.php            Plugin self-update from the backend
  class-cli.php                WP-CLI commands
uninstall.php                  Removes dumps, options and scheduled events on uninstall
tools/                         Release tooling (key generation, signing, build)
```
