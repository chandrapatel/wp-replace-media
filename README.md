# WP Replace Media

- **Contributors:** chandrapatel
- **Tags:** media, replace, attachments, thumbnails, cache-busting
- **Requires at least:** 6.0
- **Tested up to:** 6.7
- **Requires PHP:** 8.0
- **Stable tag:** 1.0.0
- **License:** GPLv2 or later
- **License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

## Description

Replace attachment files in-place while keeping URLs stable. WP Replace Media adds a Media Library row action that opens a dedicated replace screen, enforces MIME type consistency, writes the new file over the existing path using the WordPress Filesystem API, regenerates image metadata and sub-sizes, updates attachment modified dates, and records a UTC replacement timestamp used as a `ver` query param for cache-busting on full and intermediate image URLs.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin through the “Plugins” menu in WordPress.

## Usage

1. Go to Media → Library (list view).
2. Click the “Replace” row action for an attachment.
3. On the Replace Media page, choose a file with the **same MIME type** as the existing one and submit.
4. For images, thumbnails are regenerated. All URLs keep the same path, with a `ver` param reflecting the replacement timestamp.

## Disclaimer

**Use this plugin at your own risk.** It has not been tested against all possible media types or hosting environments, and it does not cover every browser-side cache-busting scenario. Always keep backups of original files before replacing media.

## Frequently Asked Questions

### Does the URL change?

No. The original file path is reused; only a cache-busting `ver` query param is added based on the replacement timestamp.

### What happens to thumbnails?

Images have metadata regenerated and intermediate sizes recreated after replacement.

### Why must the MIME type match?

To avoid broken embeds and mismatched handling in WordPress, replacements are limited to the original MIME type.

### Does it use the WordPress Filesystem API?

Yes. The uploaded temp file is read and written over the existing file via the WP Filesystem API.

### Is there a backup?

No automatic backup is created. Keep a local copy before replacing.

## Changelog

### 1.0.0

- Initial release: replace-in-place flow, thumbnail regeneration, modified date updates, and URL versioning via `_wp_replace_media_replaced` metadata.
