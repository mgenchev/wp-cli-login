# Changelog

## Unreleased

- Fix `--no-open` handling by respecting WP-CLI's normalized `open => false` flag value.
- Fix Windows login endpoint read failures by locking a separate sidecar file instead of the executing PHP script.
- Run `wp login` on `after_wp_config_load` and avoid loading `wp-settings.php`, plugins, and themes while resolving users.
- Read WordPress users, roles, and site URL through a bounded direct MySQL adapter using credentials from `wp-config.php`.
- Correctly consume WP-CLI's standard global `--user=<id|login|email>` parameter instead of treating it as a command-local option.
- Keep administrator selection separate from the existing non-administrator user picker.
- Automatically select the only account on single-user sites.
- Add an interactive action choice between opening the URL in the browser and copying it to the clipboard.
- Add `--open` and `--copy` action flags while retaining `--no-open` compatibility.
- Add browser support for macOS, Linux, WSL, and Windows with URL fallback.
- Add clipboard support for macOS, Windows, WSL, Wayland, and X11 with URL fallback.
- Create one-time login endpoints with a 1-minute lifetime, hashed token storage, and stale-file cleanup.
- Add smoke, negative, and regression coverage for user selection, global `--user`, database parsing, browser/clipboard integration, and one-time endpoint behavior.
