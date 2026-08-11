# Anhora WordPress Plugin

Store-side WordPress plugin for [Anhora](https://anhora.net): embed the chat widget, sync pages into knowledge, and (when WooCommerce is active) push catalog + Host Bridge session context.

This repository is separate from the Anhora monorepo so it can ship to wordpress.org with its own semver and CI.

## Contract

HTTP ingest and Host Bridge shapes are documented in the Anhora monorepo:

- `docs/wordpress-plugin.md`
- `docs/connectors-ecommerce-plugins.md`
- `docs/host-bridge.md`

## Install (dev)

1. Copy or symlink the `anhora/` directory into `wp-content/plugins/anhora`.
2. Activate **Anhora** in WP Admin → Plugins.
3. Configure Settings → Anhora (API base, widget id, ingest secret, deployment key).

## Phases

- **Phase 1:** embed + knowledge sync from selected pages/posts
- **Phase 2:** WooCommerce catalog ingest, shipping/payment knowledge snapshot, Host Bridge

