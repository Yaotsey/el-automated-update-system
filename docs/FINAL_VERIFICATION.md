# Final Verification: EL Automated Update System v1.1.0

Verification date: 16 November 2026
Repository: https://github.com/Yaotsey/el-automated-update-system (public)
Release: https://github.com/Yaotsey/el-automated-update-system/releases/tag/v1.1.0

## What the plugin does

The plugin has two independent capabilities.

1. Deploy: a signed GitHub webhook (`el-aas/v1/webhook`) receives a push, verifies the HMAC-SHA256 signature, and hands the commit to `EL_AAS_Deployer`, which downloads the zipball, validates it, backs up the target, swaps directories, runs a health check, and rolls back on failure.
2. Self-update: `EL_AAS_Self_Updater` registers the plugin with the WordPress plugin update system so it updates itself from its own GitHub releases.

## Defects found and fixed

| ID | Defect | Fix | Evidence |
|---|---|---|---|
| A | `remember_failure()` called but undefined, a fatal error | Defined once, caches the message in `FAIL_KEY` for `FAIL_TTL` | `class-el-aas-self-updater.php` line 159; single occurrence |
| B | Tag was stripped to `1.1.0` then used as the zipball ref, causing 404 | `$ref` keeps the original `v1.1.0`; `$version` derives the comparison value | Lines 129 and 130; package URL built from `$ref` on line 141 |
| C | Repository root layout not handled: the plugin sits in a subfolder of the zipball | `source_selection()` descends into the nested `el-automated-update-system/` folder | Lines 290 to 298; confirmed against the real v1.1.0 zipball |
| D | `wp_clean_plugins_cache( false )` left the `update_plugins` transient, so Check Now returned stale data | Now `wp_clean_plugins_cache( true )` | `class-el-aas-settings.php` line 663 |
| E | No token short-circuit, so a missing token hit GitHub on every check | Token guard removed and token made optional for public repositories | `class-el-aas-self-updater.php` lines 105 to 110 and 331 to 336 |
| F | Duplicate failure-cache constants | Only `SLUG`, `CACHE_KEY`, `CACHE_TTL`, `FAIL_KEY`, `FAIL_TTL` remain | `Select-String '^const '` returns five entries |
| G | `dirname( $source )` with a trailing slash could escape the upgrade staging directory | `trailingslashit()` and `untrailingslashit()` normalization, and the parent is taken from `$remote_source` | Lines 288, 300, 302, 306 |
| H | Duplicate `update_option_el_aas_self_auto_update` registration in two files | Registered only in the main plugin class | One occurrence in the plugin file, zero in the settings class |
| I | Uninstall did not clear the failure transient | `delete_transient( 'el_aas_self_update_error' )` added | `uninstall.php` line 41 |

## Verification evidence

| Check | Command | Result |
|---|---|---|
| PHP syntax, 8 files | `node validate-php.js ...` | All eight return OK |
| Duplicate methods | method-name scan | None |
| Duplicate hook registration | occurrence count | 1 in main plugin, 0 in settings |
| Build | `build-zip.ps1` | `ZIP_BUILT`, 29,083 bytes |
| Package structure | `tar -tf` | 12 entries under `el-automated-update-system/` |
| Version consistency | header, constant, stable tag | `1.1.0` in all three |
| Anonymous release lookup | `curl` `/releases/latest` | HTTP 200 |
| Anonymous zipball | `curl` `/zipball/v1.1.0` | HTTP 302 to codeload (redirect is expected and followed) |
| Zipball layout | download and `tar -tf` | Top folder `Yaotsey-el-automated-update-system-c618d9e/`, then nested `el-automated-update-system/` |
| Release asset layout | download and `tar -tf` | Top folder `el-automated-update-system/` with the plugin header inside |
| Shipped version | inspect extracted asset | Header `1.1.0`, constant `1.1.0`, stable tag `1.1.0` |
| Release publication | `gh release view v1.1.0` | Published, not draft, not prerelease, one asset |
| Asset byte size | release metadata | 28,944 bytes |
| Automated pipeline | `gh run view 43795275509` | All eight steps succeeded |

## Enough for the plugin to work with no configuration

The plugin defaults are already correct for a public repository:

- `el_aas_self_repository` default `Yaotsey/el-automated-update-system`
- `el_aas_self_branch` default `main`
- `el_aas_self_auto_update` default `1`
- `el_aas_self_github_token` default empty, which the API client tolerates

Installing and activating the plugin is the whole setup. WordPress will find `v1.1.0` on its own.

## Known limitation

No WordPress installation, PHP runtime, or WP-CLI exists on the build machine, so the update cycle has not been executed end to end inside a live WordPress site. The API endpoints, zipball layout, and source-selection logic have been verified against the real published artifacts, but the first live install remains the acceptance test.
