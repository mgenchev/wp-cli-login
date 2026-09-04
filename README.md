# WP Login

A small WP-CLI package that creates a short-lived, one-time WordPress login link and can open it directly in the default browser.

## Requirements

- PHP >= 7.4
- WP-CLI >= 2.8
- A normal WordPress installation accessible to WP-CLI
- The WordPress root must be writable long enough to create the temporary login endpoint

## Installation

Local development:

```bash
wp package install .
```

After publishing:

```bash
wp package install https://github.com/<owner>/<repository>.git
```

## Usage

Interactive login:

```bash
wp login
```

If the site has only one WordPress user, that user is selected automatically. With two or more users, the command asks whether to log in as an administrator or choose an existing non-administrator WordPress user.

Select a user directly:

```bash
wp login --user=admin
wp login --user=42
wp login --user=user@example.com
```

Do not open the browser automatically:

```bash
wp login --no-open
```

## How it works

The command creates a random, short-lived PHP endpoint in the WordPress root and opens a one-time URL containing a cryptographically random token. The endpoint loads WordPress, sets the normal WordPress authentication cookie for the selected user, redirects to `wp-admin`, and deletes itself.

The raw token is never stored in the temporary file. Login links expire after 1 minute and are intended for one use only. Old temporary endpoints are cleaned up on subsequent runs.

The command is registered with `@when before_wp_load` so it can show progress before the heavier WordPress bootstrap begins. It then asks WP-CLI to load WordPress explicitly, because user roles, user lookup, canonical site URL handling, and WordPress authentication cookies are core WordPress behavior and are safer to use directly than to reimplement independently.

During bootstrap, `wp login` shows `Preparing WordPress...`. On interactive terminals the spinner is cleared when loading completes, so no separate WordPress-ready status remains in the console. If animated terminal output is unavailable, it falls back to an immediate static status indicator.

## Browser support

Automatic browser opening is supported on:

- macOS via the system `open` command;
- Linux desktop sessions via `xdg-open` or `gio`;
- WSL via `wslview` or Windows browser integration when available;
- Windows via `explorer.exe`, with `cmd.exe start` as a fallback.

When an automatic launch is requested, the command also prints the one-time URL so it remains usable if the OS accepts the launch request but the browser does not appear. If no supported browser launcher is available, the command falls back to printing the URL only.

## Development tests

```bash
php tests/run.php
```
