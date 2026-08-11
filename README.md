# Anhora WordPress Plugin

Store-side WordPress plugin for [Anhora](https://anhora.net): embed the chat widget, sync pages into knowledge, and (when WooCommerce is active) push catalog + Host Bridge session context.

This repository is separate from the Anhora monorepo so it can ship to wordpress.org with its own semver and CI.

## Download (stable)

Install via **Plugins → Add New → Upload Plugin**:

**[Latest release zip](https://github.com/denys-vodniakov/anhora-wordpress/releases/latest)**  
Asset name: `anhora-x.y.z.zip` (contains the `anhora/` plugin folder).

## Contract

HTTP ingest and Host Bridge shapes are documented in the Anhora monorepo:

- `docs/wordpress-plugin.md`
- `docs/connectors-ecommerce-plugins.md`
- `docs/host-bridge.md`

## Install (dev)

1. Copy or symlink the `anhora/` directory into `wp-content/plugins/anhora`.
2. Activate **Anhora** in WP Admin → Plugins.
3. Configure Settings → Anhora (API base, widget id, ingest secret, deployment key).

## Versioning & release

Semver lives in three places (must match):

- `anhora/anhora.php` header `Version:`
- `anhora/anhora.php` constant `ANHORA_VERSION`
- `anhora/readme.txt` `Stable tag:`

Build a WordPress-installable zip locally:

```bash
./scripts/build-zip.sh
# → dist/anhora-0.1.0.zip
```

Publish:

```bash
# 1. bump the three version fields above
# 2. commit on main
git tag v0.1.0
git push origin main --tags
```

Pushing a `v*` tag runs GitHub Actions → creates a Release with `anhora-{version}.zip`.

## Phases

- **Phase 1:** embed + knowledge sync from selected pages/posts
- **Phase 2:** WooCommerce catalog ingest, shipping/payment knowledge snapshot, Host Bridge
