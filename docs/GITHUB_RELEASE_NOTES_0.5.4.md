# Commerce Promotions 0.5.4 — release notes

## Changed

- Automatic updates now come from a private update server via the bundled
  [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) v5
  library (`lib/plugin-update-checker/`). The bespoke GitHub-release updater
  (`src/Infrastructure/GithubUpdater.php`) has been removed.
- The update check runs only when the `PRIVATE_UPDATE_SERVER` constant is defined
  in `wp-config.php` (admin/cron only, no frontend HTTP).
- Added a CI workflow that uploads the release ZIP to the update server on each
  `v*` tag.

## Install

Deploy `mp-commerce-promotions` **0.5.4** / tag **`v0.5.4`**. Define
`PRIVATE_UPDATE_SERVER` in `wp-config.php` on the target site to receive updates.

Rollback: **0.5.3** / `v0.5.3`.
