# Changelog

## Unreleased

- Keep the completed bootstrap spinner out of the final console output.
- Exclude administrator accounts from `Choose existing user`; administrators remain available through the dedicated administrator option.
- Reduce one-time login-link lifetime from 2 minutes to 1 minute.
- Keep browser-launch failure silent and fall back to printing the URL.
- Remove the redundant login-link-created and `Success:` status lines.
- Initial `wp login` command.
- Interactive administrator or existing-user selection.
- Skip the interactive user picker and automatically select the only user on single-user sites.
- Direct `--user=<id|login|email>` selection.
- Short-lived one-time login endpoint with automatic cleanup.
- Default-browser opening with `--no-open` fallback.
- Fixed Windows browser launching by replacing `rundll32.exe` with `explorer.exe` and `cmd.exe start` fallback.
- Added WSL browser-launch support and safer headless Linux detection.
- Keep the one-time URL visible after a browser launch request so false-positive OS launch results do not strand the user.
- Initial smoke and regression tests.
- Start `wp login` before WordPress loads so progress can be shown during bootstrap.
- Add a `Preparing WordPress...` spinner with a static fallback when animated terminal output is unavailable.
