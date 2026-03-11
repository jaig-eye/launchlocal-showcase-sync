# LaunchLocal Showcase Sync

A WordPress plugin that syncs LaunchLocal Custom Object (Showcase) records to WordPress with SEO optimisation, taxonomy support, and two-way sync.

## Features

- Forward sync: GHL → WordPress (auto-creates/updates posts from Showcase records)
- Back sync: WordPress → GHL (pushes WP post changes back to GoHighLevel)
- SEO engine: auto-generates meta titles, descriptions, and slugs
- Field mapping: flexible mapping between GHL fields and WP post/meta/taxonomy fields
- Scheduled cron sync with configurable batch sizes

## Installation

1. Download the latest release ZIP from the [Releases](../../releases) page
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate

### Auto-updates

Once the plugin is installed, WordPress will automatically detect new releases from this repository and prompt you to update via the standard **Plugins → Updates** screen — no manual downloads needed.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- An active LaunchLocal / GoHighLevel account with API access

## Configuration

After activation, go to **Settings → LaunchLocal Sync** to enter your GHL API key and configure field mappings.

## Versioning

Releases follow [Semantic Versioning](https://semver.org/). Each GitHub release tagged as `vX.Y.Z` triggers an update notification on all connected WordPress sites.

---

## Changelog

### 4.5.2
**Code audit fixes — orphaned options, reserved meta key conflict.**

**Fixed**
* `ghl_sync_origin_migrated_v1` was written by the origin-migration routine but never cleaned up on uninstall. Added to `GithubUpdater::ALL_OPTIONS` and `uninstall.php` so it is fully removed when the plugin is deleted.
* `ghl_sync_taxonomy_slug` was removed from plugin settings in v4.5.0 but remained listed in `uninstall.php`. Removed.
* `update_post_meta( $post_id, 'category', ... )` in `sync_taxonomy()` used the reserved WordPress meta key `category` and stored a value that was never read back anywhere. The call served no purpose and risked conflicts with other plugins that read or write `category` post meta. Removed.

---

### 4.5.1
**Back-sync reliability — batch completeness, duplicate prevention, and cron back-sync.**

**Fixed**
* Back-sync batch `has_more` logic was based on the pre-batch backlog count, so a batch of exactly N posts (where N = batch_size) always returned `has_more: false` — even if some posts failed and remained in the backlog. `has_more` is now computed from the actual post-batch count, so partial batches correctly trigger the next run.
* GHL record created but ID not saved to WordPress: when GHL's response didn't include the ID in the expected shape, the fallback title search fired immediately — before GHL's eventual-consistency index caught up — and found nothing. A 0.8 s delay is now inserted before the fallback search, giving GHL time to index the new record.
* Added `$result['records'][0]['id']` as a fallback ID response path to cover additional GHL API response shapes.
* Back-sync batches now run a pre-flight dedup check against the cached GHL record list before creating. If a record with the same title already exists in GHL (from a previous partial push where the ID was never saved back), the post is linked to that existing record instead of creating a duplicate.
* `ghl_sync_cron_offset` and `ghl_scheduled_back_sync` were not cleared on uninstall/wipe. Both are now removed by `wipe_plugin_data()` and `uninstall.php`.
* Removed stale `ghl_sync_taxonomy_slug` option from `GithubUpdater::ALL_OPTIONS` (option was removed in 4.5.0).

**Added**
* Back-sync cron (`ghl_scheduled_back_sync`): fires on the same user-configured schedule as the forward sync (offset +30 s to avoid API contention). Processes one batch of backlog posts per fire; the backlog shrinks naturally across fires with no offset tracking needed. Silently exits when there is nothing to push.
* Progress bar for back-sync now shows a "Done" label with final counts and snaps to 100% on completion, regardless of how many posts errored.

---

### 4.5.0
**Fixes & hardening across sync engine, taxonomy, and settings.**

**Fixed**
* Cron self-healing (`CronManager::maybe_reschedule`) now fires on the `init` hook (every request, including frontend) rather than `admin_init`. Prevents the scheduled sync from silently dying after a WordPress core update or site migration without any admin visit.
* Removed deprecated `ini_get('safe_mode')` call (removed in PHP 7.0) from AJAX sync handlers. `set_time_limit()` is now called directly.

**Improved**
* Taxonomy term matching now handles GHL → WP slug format differences. GHL slugs use underscores; WordPress uses dashes. A `&` in a category name can produce double-underscores in GHL (e.g. `bed_covers__tonneau_toppers`). The sync engine normalises these before lookup so existing WP terms are matched correctly instead of creating duplicates.
* The Sync tab (LaunchLocal Records table) no longer shows WordPress-origin backlog posts — only GHL-origin orphans are surfaced there. WP-created posts belong to the Back-Sync tab.
* Back-Sync tab now shows a clear warning banner: images are not pushed to LaunchLocal, and back-sync is optional.
* Removed the standalone **Category Taxonomy Slug** settings field. The taxonomy slug is now set directly via the WP field key column in Field Mapping (e.g. `showcase-category`), removing a source of misconfiguration.
* Default field map updated: removed the `showcase_description → post_content` (post type) entry to avoid overwriting MetaBox-managed content fields; default taxonomy WP key changed from `category` to `showcase-category`.
* The `showcase` CPT is no longer re-registered by this plugin — MetaBox fully owns it. Eliminates icon and argument overrides that conflicted with MetaBox configuration.
* Added **Settings** quick-link to the plugin row on the Plugins screen.

---

### 4.4.0
* Added **Check for Update** button to the plugin row — forces an immediate GitHub release check and clears the update cache.
* Added **Uninstall Plugin** button to the plugin row — wipes all plugin options, transients, and cron events then removes plugin files cleanly.
* Added `uninstall.php` fallback for WordPress native uninstall hook.

### 4.3.3
* Initial public release.
