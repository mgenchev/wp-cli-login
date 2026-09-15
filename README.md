# WP Login

A small WP-CLI package that creates a short-lived, one-time WordPress login link and lets you open it in the default browser or copy it to the clipboard.

## Requirements

- PHP >= 7.4
- WP-CLI >= 2.8
- `mysqli` PHP extension
- A normal MySQL/MariaDB-backed WordPress installation accessible to WP-CLI
- The WordPress root must be writable long enough to create the temporary login endpoint

## Installation

Local development:

```bash
wp package install .
```

From GitHub:

```bash
wp package install https://github.com/mgenchev/wp-cli-login.git
```

## Usage

Interactive login:

```bash
wp login
```

If the site has only one WordPress user, that user is selected automatically. With two or more users, the command asks whether to log in as an administrator or choose an existing non-administrator user.

After selecting the account, choose whether to open the one-time URL in the browser or copy it to the clipboard. The action is selected before the one-minute login link is created.

Select a user directly with WP-CLI's standard global `--user` parameter:

```bash
wp login --user=admin
wp login --user=42
wp login --user=user@example.com
```

Skip the action prompt when needed:

```bash
wp login --user=admin --open
wp login --user=admin --copy
```

For backward compatibility, `--no-open` prints the URL without opening or copying it.

## How it works

`wp login` runs on WP-CLI's `after_wp_config_load` hook. At that point `wp-config.php` has been evaluated, but `wp-settings.php`, plugins, and themes have not been loaded.

The command uses a small read-only MySQL adapter to resolve:

- the WordPress site URL;
- users;
- per-site user roles.

This keeps the CLI command isolated from plugin/theme bootstrap failures and avoids loading the full WordPress runtime just to choose an account.

After a user is selected, the package creates a random temporary PHP endpoint in the WordPress root and produces a one-time URL containing a cryptographically random token. The raw token is never stored in the temporary file.

Only when that URL is opened does the temporary endpoint load WordPress. It verifies the selected user still exists, sets the normal WordPress authentication cookie, redirects to `wp-admin`, and deletes itself.

Login links expire after 1 minute and are intended for one use only. Old temporary endpoints are cleaned up on subsequent runs.

## Browser and clipboard support

Automatic browser opening is supported on:

- macOS via `open`;
- Linux desktop sessions via `xdg-open` or `gio`;
- WSL via `wslview` or Windows browser integration when available;
- Windows via `explorer.exe`, with `cmd.exe start` as a fallback.

When browser opening is requested, the one-time URL is also printed so it remains usable if the OS accepts the launch request but no browser appears.

Clipboard copying is supported on:

- macOS via `pbcopy`;
- Windows and WSL via `clip.exe`;
- Linux Wayland via `wl-copy`;
- Linux X11 via `xclip` or `xsel`.

If no supported clipboard utility is available, the command falls back to printing the URL.

## Development tests

```bash
php tests/run.php
```
