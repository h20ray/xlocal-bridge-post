# xLocal Bridge Post Architecture

## Directory Layout

- `xlocal-bridge-post.php`: Plugin bootstrap.
- `includes/class-xlocal-bridge-loader.php`: Central class/file loader.
- `includes/Admin/`: Admin UI and settings handling.
- `includes/Core/`: Runtime sync engine (sender, receiver, updater).
- `includes/class-xlocal-bridge-*.php`: Backward-compatible shim files.
- `admin/`: Admin CSS/JS assets.

## Admin Settings Split

`Xlocal_Bridge_Settings` is now a thin facade that composes traits:

- `includes/Admin/Traits/trait-xlocal-bridge-settings-store.php`
  - defaults, option read/write, sanitization.
- `includes/Admin/Services/class-xlocal-bridge-admin-action-service.php`
  - admin-post handlers and notices.
- `includes/Admin/Traits/trait-xlocal-bridge-settings-page.php`
  - page-level composition, loading:
    - `includes/Admin/Traits/Page/trait-xlocal-bridge-settings-page-layout.php`
    - `includes/Admin/Traits/Page/trait-xlocal-bridge-settings-page-tabs.php`
    - `includes/Admin/Traits/Page/trait-xlocal-bridge-settings-page-fields.php`

`trait-xlocal-bridge-settings-page-tabs.php` itself composes smaller tab groups:

- `trait-xlocal-bridge-settings-page-tabs-overview-receiver.php`
- `trait-xlocal-bridge-settings-page-tabs-sender-advanced.php`
- `trait-xlocal-bridge-settings-page-tabs-debug-logs.php`
- `trait-xlocal-bridge-settings-page-tabs-bulk-docs.php`

## Class Locations

- `Xlocal_Bridge_Settings`: `includes/Admin/class-xlocal-bridge-admin-settings.php`
- `Xlocal_Bridge_Sender`: `includes/Core/class-xlocal-bridge-core-sender.php`
- `Xlocal_Bridge_Receiver`: `includes/Core/class-xlocal-bridge-core-receiver.php`
- `Xlocal_Bridge_Updater`: `includes/Core/class-xlocal-bridge-core-updater.php`

## Notes

- Shim files under `includes/class-xlocal-bridge-*.php` exist for compatibility with any legacy direct includes.
- New code should target `Admin/` and `Core/` paths.
