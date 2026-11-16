=== EL Automated Update System ===
Contributors: el
Tags: github, deployment, automatic updates, continuous deployment
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Deploy GitHub pushes to a WordPress plugin or theme with backups, atomic replacement, health checks, and automatic rollback.

== Description ==

EL Automated Update System turns a GitHub push into a safe WordPress deployment.

When a commit is pushed to the configured branch, GitHub calls a signed webhook. The plugin verifies the HMAC signature, downloads the exact commit as a zipball, validates the archive, backs up the current target, swaps the new files into place, runs a health check, and rolls back automatically if anything fails.

= Features =

* Signed GitHub webhook verification (HMAC SHA-256).
* Asynchronous processing so GitHub receives an immediate response.
* Idempotent deployments: a commit is deployed only once.
* Concurrency lock to prevent overlapping deployments.
* Safe zip extraction with path traversal protection.
* Full backup of the current plugin or theme before replacement.
* Atomic directory swap when the filesystem allows it, with a copy-based fallback.
* Post-deployment health check with automatic rollback.
* Automatic retries for transient GitHub API failures.
* File-based logging with rotation and secret redaction.
* Manual deployment button in the admin.
* Optional failure notification email.

== Installation ==

1. Upload the `el-automated-update-system` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open the EL Auto Update menu and configure:
   * GitHub repository in `owner/name` format.
   * Branch to deploy.
   * Optional personal access token for private repositories.
   * Webhook secret (generate one with the button).
   * Target type (plugin or theme) and target slug (folder name).
   * Optional source subdirectory for monorepos.
4. Copy the webhook URL into the GitHub repository settings.
5. Set the webhook content type to `application/json` and paste the secret.
6. Select the `push` event.

== Frequently Asked Questions ==

= Does the plugin update itself? =

Yes. The updater plugin updates itself through the standard WordPress plugin update system, sourced from its own GitHub repository. It reads the latest release or tag, compares it with the installed version, and injects a normal update notice. When the automatic self-update toggle is enabled (the default), WordPress installs the new version without a click. When the toggle is disabled, WordPress shows the normal Update Now notice and you install the update yourself.

Self-update targets the updater plugin only. The deployer still refuses to deploy the updater plugin through the webhook pipeline, so a push to the target repository cannot replace the running updater.

= What do I need to set up self-updates? =

For a public repository, nothing beyond installing the plugin. The repository and branch default to the project's own GitHub repository, and the update check runs without credentials.

For a private repository:

1. Create a fine-grained personal access token with Contents: Read and Metadata: Read for the updater repository.
2. Paste the token into the Self-Update section of the settings page and save.
3. Keep the automatic self-update toggle enabled if you want zero-click updates.

In both cases, create a release with a version tag such as v1.1.0 and attach the zip built by build-zip.ps1.

= How does the self-update work with a private repository? =

WordPress cannot download a private GitHub release without credentials. When a token is configured, the plugin hooks the upgrader download step and fetches the release package with a bearer token, then hands WordPress a local temporary file. When no token is configured, the plugin leaves the download to WordPress, which is correct for a public repository.

= Does it work with private repositories? =

Yes. Add a fine-grained personal access token with read access to repository contents. The token is optional and is only needed for private repositories.

= What happens if a deployment breaks the site? =

The plugin keeps a full backup of the previous version. If the health check fails, the previous version is restored automatically.

== Changelog ==

= 1.1.0 =
* Added WordPress-native self-update support through a GitHub repository.
* Added a Self-Update settings section with repository, branch, optional token, and an automatic update toggle.
* Added a Check for Updates Now button that clears the cached lookup and refreshes the WordPress update check.
* Added upgrader integration for release downloads, source folder renaming, and auto-update registration.
* Made the self-update token optional so a public repository needs no configuration.
* Added the self-update token to the log redaction list.
* Updated the uninstall routine to remove self-update options and the auto-update registration.

= 1.0.0 =
* Initial release.
