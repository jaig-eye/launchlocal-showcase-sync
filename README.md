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
