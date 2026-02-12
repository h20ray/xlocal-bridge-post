# Changelog

All notable changes to this project are documented in this file.

## 0.5.2 - 2026-02-12

### Added
- Dedicated `Xlocal_Bridge_Admin_Action_Service` class for all admin-post handlers and admin notices.
- Backward-compatible wrapper methods on `Xlocal_Bridge_Settings` for legacy callback safety.
- Receiver-side content-aware diagnostics tab and ingest logging pipeline.

### Changed
- Refactored plugin structure into loader + Admin/Core folders with compatibility shims retained.
- Split admin settings monolith into focused store/page/action concerns and smaller page/action modules.
- Receiver ingest now supports optional prepend of featured image when incoming content has no `<img>`.
- Receiver taxonomy normalization now decodes HTML entities before sanitization (for cleaner terms).
- Version bumped to `0.5.2`.
- Admin asset cache-busting version bumped to `0.5.2`.

## 0.5.1 - 2026-02-12

### Added
- New **Bulk Send** admin tab and action label replacing **Bulk Import** language.
- Backward-compatible support for legacy bulk action/nonce endpoints.
- Clear operator-focused Bulk Send notices for testing and production confidence.
- Built-in GitHub updater integration for wp-admin update notices and one-click upgrades.
- New settings status badge for updater configuration visibility.
- Default updater source now points to official repo (`h20ray/xlocal-bridge-post`) without requiring `wp-config.php` edits.
- GitHub Actions workflow to publish a fixed-root plugin ZIP asset on each `main` commit (`commit-main` rolling release).

### Changed
- Sender retry flow now regenerates `X-Xlocal-Timestamp`, `X-Xlocal-Nonce`, and `X-Xlocal-Signature` on every retry attempt.
- `save_post` dispatch no longer skips all `REST_REQUEST` traffic; now uses a safer in-request dispatch guard to prevent duplicate loops.
- Bulk send selection is now deterministic: oldest eligible unsent posts are selected first.
- Bulk send post type validation now follows the selected bulk post type from UI.
- Bulk query remote-source guard relation tightened for safer filtering.
- Updater now supports channels: `commit` (default) and `release`.
- Commit-mode updater now prefers rolling release asset ZIP (fixed root folder) and falls back to codeload when unavailable.
- Plugin version bumped to `0.5.1`.
- Admin asset cache-busting version bumped to `0.5.1`.

### Docs
- README terminology updated from **Bulk Import** to **Bulk Send**.
- README backfill section updated with deterministic behavior and test guidance.

## 0.5.0

### Initial
- Unified Sender + Receiver plugin release with secure ingest, deduplication, mapping controls, and admin settings UI.
