# Anhora WordPress Plugin

Store-side WordPress plugin for [Anhora](https://anhora.net): embed the chat widget, sync pages into knowledge, and (when WooCommerce is active) push catalog + Host Bridge session context.

This repository is separate from the Anhora monorepo so it can ship to wordpress.org with its own semver and CI.

## Download (stable)

Install via **Plugins → Add New → Upload Plugin**:

**[Latest release zip](https://github.com/denys-vodniakov/anhora-wordpress/releases/latest)**  
Asset name: `anhora-x.y.z.zip` (contains the `anhora/` plugin folder).

After the plugin is listed on WordPress.org, install from **Plugins → Add New** by searching for Anhora. Keep this GitHub repo as the source; wordpress.org SVN is the public distribution.

## Contract

HTTP ingest and Host Bridge shapes are documented in the Anhora monorepo:

- `docs/wordpress-plugin.md`
- `docs/connectors-ecommerce-plugins.md`
- `docs/host-bridge.md`

## Install (dev)

1. Copy or symlink the `anhora/` directory into `wp-content/plugins/anhora`.
2. Activate **Anhora** in WP Admin → Plugins.
3. Configure Settings → Anhora (API base, integration id, widget id, ingest secret, deployment key).

## Versioning & release

Semver lives in three places (must match):

- `anhora/anhora.php` header `Version:`
- `anhora/anhora.php` constant `ANHORA_VERSION`
- `anhora/readme.txt` `Stable tag:`

Build a WordPress-installable zip locally:

```bash
./scripts/build-zip.sh
# → dist/anhora-0.4.0.zip
```

Publish a GitHub Release:

```bash
# 1. bump the three version fields above
# 2. commit on main
git tag v0.3.1
git push origin main --tags
```

Pushing a `v*` tag runs GitHub Actions → creates a Release with `anhora-{version}.zip`.

## WordPress.org directory

Submit **`dist/anhora-x.y.z.zip`**, not a GitHub source zip (that includes `.github` / `.cursor` and fails Plugin Check).

1. WordPress.org account with **2FA**. `readme.txt` `Contributors:` must be that username (currently `denysvodniakov`).
2. Install [Plugin Check](https://wordpress.org/plugins/plugin-check/), run it on `anhora` with the **Plugin Repo** category, and fix any errors.
3. Upload the zip at [Add plugin](https://wordpress.org/plugins/developers/add/).
4. After approval, checkout SVN and put plugin files in `trunk/` (not `trunk/anhora/`):

```bash
svn co https://plugins.svn.wordpress.org/anhora svn-anhora
rsync -a --delete anhora/ svn-anhora/trunk/
svn add --force svn-anhora/trunk/*
svn cp svn-anhora/trunk svn-anhora/tags/0.3.1
svn ci -m "Tagging 0.3.1" --username YOUR_WPORG_USERNAME
```

Do not set `Stable tag: trunk`. Keep wordpress.org in sync with GitHub releases.

Optional directory artwork (banners/icons/screenshots) goes in SVN `/assets/`, not inside the plugin zip. See [plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

## Phases

- **Content:** save/delete delta events plus atomic selected-page reconciliation
- **Commerce:** save/delete product events, background keyset snapshots, and Host Bridge

Durable writes are scoped by the Anhora integration id. Full scans use one
begin/page/commit generation; failed pages never replace the live source. The
Widget ID remains specific to embed and session Host Bridge behavior.

Catalog scans checkpoint the current keyset cursor and uploaded page after every
successful batch. Transient failures retry with backoff, a watchdog recovers a
worker that disappears between batches, and Settings → Anhora can either resume
the saved run or abort it and restart from the beginning.

The plugin reports a versioned capability manifest after settings change and
before manual sync. WordPress advertises `content.search`; WooCommerce adds the
catalog search and recommendation reads. Order data exposed to the browser Host
Bridge is not advertised as an authenticated status action.
