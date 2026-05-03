# WP Replace Media

- **Contributors:** chandrapatel
- **Tags:** media, replace, attachments, thumbnails, revisions, backup, restore, cache-busting, cdn
- **Requires at least:** 6.0
- **Tested up to:** 6.7
- **Requires PHP:** 8.0
- **Stable tag:** 1.0.0
- **License:** GPLv2 or later
- **License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

## Description

Replace attachment files in-place while keeping URLs stable. WP Replace Media adds a Replace entry point in the Media Library list view, attachment edit screen, and grid view modal, opens a dedicated tabbed Replace screen, enforces MIME type consistency, writes the new file over the existing path using the WordPress Filesystem API, regenerates image metadata and sub-sizes, updates attachment modified dates, and records a UTC replacement timestamp used as a `ver` query param for cache-busting on full and intermediate image URLs.

Every replacement creates an automatic file backup, logged to a custom `{prefix}wrm_revisions` revisions table, viewable and restorable from a Revisions tab on the Replace screen. WPVIP edge cache is purged automatically; other CDNs can hook into the documented action to purge their own caches.

## Features

- **In-place replace** — file path stays the same, URLs keep working.
- **Multiple entry points** — Replace row action in the Media Library list view, button on the attachment edit screen, and a Replace control in the grid view modal.
- **Tabbed Replace screen** — Upload tab for the new file (drag-and-drop with file input fallback) and Revisions tab listing every replacement.
- **Scoped to safe types** — images and PDFs only (extendable via `wp_replace_media_allowed_types` filter).
- **MIME type enforcement** — replacement file must match the original MIME type.
- **Automatic backup** — every replacement copies the existing file to `wp-content/uploads/wp-replace-media-backups/{attachment_id}/{uuid}-{filename}` via the WP Filesystem API. Replacement is aborted if the backup copy fails.
- **Revisions log** — custom `{prefix}wrm_revisions` table records each replacement (filename, size, MIME, user, UTC timestamp, backup reference) and survives across replacements.
- **Restore** — restore any prior backup as the current file; the restore itself is logged as a new revision linked to the source.
- **Delete backup** — remove a backup file from disk while keeping the audit row.
- **Cache-busting URLs** — adds a `ver=<utc-timestamp>` query param to attachment URLs, image src arrays, and srcset sources after a replacement.
- **Built-in WPVIP cache purge** — full-size and sub-size URLs are purged via `wpcom_vip_purge_edge_cache_for_url()` when available.
- **CDN-ready hook** — `wp_replace_media_file_replaced` fires with all size URLs for third-party CDN/offload integrations.
- **Capability and nonce hardened** — every entry point checks `upload_files` plus `edit_post( $attachment_id )` and a request-specific nonce.
- **i18n** — text domain `wp-replace-media`, `.pot` file in `/languages`.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin through the "Plugins" menu in WordPress. The `{prefix}wrm_revisions` table is created on activation.

## Usage

1. Open the Replace screen from any of these entry points:
   - **Media → Library (list view)** — click the **Replace** row action.
   - **Attachment edit screen** — click **Replace Media** in the side meta box.
   - **Media grid view modal** — click **Replace** in the attachment details form.
2. The Replace screen shows attachment details and two tabs:
   - **Upload** — drop a replacement file (or use the file picker). The file must have the same MIME type as the original. Submit to replace.
   - **Revisions** — list of all prior replacements for this attachment. Each row links to the backup file (opens in a new tab) and exposes **Restore** and **Delete backup** actions.
3. After replacement, image metadata is regenerated and all attachment URLs receive a `ver` query param reflecting the new replacement timestamp.

A backup is created automatically on every replacement; no opt-in is required.

## Disclaimer

**Use this plugin at your own risk.** It has not been tested against all possible media types or hosting environments, and it does not cover every browser-side or CDN cache-busting scenario. Backups are stored under `wp-content/uploads/wp-replace-media-backups/` — keep them in your offsite backup strategy.

## Frequently Asked Questions

### Does the URL change?

No. The original file path is reused; only a cache-busting `ver` query param is added based on the replacement timestamp. The `ver` param is appended to the full attachment URL, image src arrays, and srcset sources.

### What happens to thumbnails?

For images, metadata is regenerated and intermediate sizes are recreated after replacement.

### Why must the MIME type match?

To avoid broken embeds and mismatched handling in WordPress, replacements are limited to the same MIME type as the original.

### Which file types can be replaced?

Images (`image/*`) and PDFs (`application/pdf`) only. The Replace entry points are suppressed for all other attachment types. The allowed list can be extended with the `wp_replace_media_allowed_types` filter.

### Does it use the WordPress Filesystem API?

Yes. All file reads, writes, copies, and deletes go through `WP_Filesystem`. Direct PHP file functions are not used.

### Is there a backup?

Yes — every replacement is preceded by a backup of the existing file. If the backup copy fails, the replacement is aborted entirely. Backups are stored at `wp-content/uploads/wp-replace-media-backups/{attachment_id}/{uuid}-{filename}` and tracked in the `{prefix}wrm_revisions` table.

### Can I restore a previous version?

Yes. Open the Replace screen for the attachment, switch to the **Revisions** tab, and click **Restore** on any row whose backup is still present. The current file is itself backed up first, then replaced with the selected revision's contents. The restore is logged as a new revision row, linked to the source revision.

### Can I delete old backups?

Yes. The **Delete backup** action removes the backup file from disk and marks the revision row as `is_backup_deleted = 1`. The audit row is preserved; only the backup file is removed.

### What CDNs are supported out of the box?

WordPress VIP edge cache is purged automatically via `wpcom_vip_purge_edge_cache_for_url()` when the function is available. For other CDNs (Cloudflare, WP Engine, S3 offload plugins, etc.), use the `wp_replace_media_file_replaced` action — it passes the attachment ID, file path, and an array of full and sub-size URLs ready to feed into the CDN's purge API.

## Hooks

### `wp_replace_media_pre_replace` (action)

Fires before backup and file write. Useful for cancelling or auditing a replacement attempt.

- Arguments: `$attachment_id`
- A callback may call `wp_die()` to abort the operation.
- Since: 1.0.0

### `wp_replace_media_file_replaced` (action)

Fires after the file is written and before metadata regeneration. Primary integration point for CDN purges and offload plugins.

- Arguments: `$attachment_id`, `$file_path`, `$size_urls`
- Since: 1.0.0

Example:

```php
add_action(
	'wp_replace_media_file_replaced',
	static function ( int $attachment_id, string $file_path, array $size_urls ): void {
		unset( $attachment_id, $file_path );

		foreach ( $size_urls as $url ) {
			// Trigger your CDN/plugin purge for each URL.
		}
	},
	10,
	3
);
```

Cloudflare and other API-driven CDNs usually require site-specific credentials, so this hook is the recommended integration point instead of bundled API calls.

### `wp_replace_media_completed` (action)

Fires after all database and meta updates are complete.

- Arguments: `$attachment_id`, `$revision_id`
- Since: 1.0.0

### `wp_replace_media_allowed_types` (filter)

Modify the list of allowed MIME type prefixes. Default: `[ 'image', 'application/pdf' ]`. Matching is done with `str_starts_with()`, so `'image'` matches any `image/*` MIME type.

- Arguments: `$types`
- Since: 1.0.0

```php
add_filter(
	'wp_replace_media_allowed_types',
	static function ( array $types ): array {
		$types[] = 'video/mp4';
		return $types;
	}
);
```

### `wp_replace_media_capability` (filter)

Override the required capability. Default: `'upload_files'`. The plugin additionally enforces `current_user_can( 'edit_post', $attachment_id )` regardless of this filter.

- Arguments: `$cap`, `$attachment_id`
- Since: 1.0.0

## Data Storage

- **Custom table** `{prefix}wrm_revisions` — one row per replacement event (including restores). Created on activation via `dbDelta()`.
- **Post meta** `_wrm_replaced_at` (UTC timestamp) — most recent replacement, used for the `ver` query param.
- **Post meta** `_wrm_has_backup` (0/1) — quick check used to gate the Restore action without a DB query per row.
- **Backups** — `wp-content/uploads/wp-replace-media-backups/{attachment_id}/{uuid}-{filename}`. Backup paths are stored relative to the uploads basedir so they remain valid across server moves.

## AI Disclosure

This plugin was developed with the assistance of [Claude](https://claude.ai) (Anthropic's AI) and [GitHub Copilot](https://github.com/features/copilot). All code, structure, and documentation were generated through a collaborative session between the author and these AI tools. The implementation has been reviewed and is maintained by the author.

## Changelog

### 1.0.0

- Initial release.
- Replace-in-place flow with thumbnail regeneration, modified date updates, and URL versioning via a UTC replacement timestamp meta.
- Tabbed Replace screen (Upload / Revisions) using WP core admin styles.
- Multiple entry points: Media Library row action, attachment edit screen meta box, and grid view modal.
- Restricted to images and PDFs (extendable via filter).
- Automatic backup before every replacement using the WP Filesystem API; aborts replacement on backup failure.
- Custom `{prefix}wrm_revisions` table for full revision history.
- Restore and Delete backup actions in the Revisions tab.
- Hardened nonce and capability checks (`upload_files` + `edit_post( $attachment_id )`).
- Hooks: `wp_replace_media_pre_replace`, `wp_replace_media_file_replaced`, `wp_replace_media_completed`.
- Filters: `wp_replace_media_allowed_types`, `wp_replace_media_capability`.
- Built-in WPVIP edge cache purge via `wpcom_vip_purge_edge_cache_for_url()`.
- i18n: `.pot` file and `load_plugin_textdomain()`.
