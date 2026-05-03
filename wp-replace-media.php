<?php
/**
 * Plugin Name: WP Replace Media
 * Description: Replace attachment files in-place while keeping URLs and thumbnails intact. Includes automatic backups, a revisions log with restore, MIME type enforcement, cache-busting `ver` URLs, and built-in WPVIP edge cache purge.
 * Version:     1.0.0
 * Author:      Chandra Patel
 * Author URI:  https://chandra.dev
 * Plugin URI:  https://github.com/chandrapatel/wp-replace-media
 * Update URI:  https://github.com/chandrapatel/wp-replace-media
 * License:     GPL-2.0-or-later
 * Text Domain: wp-replace-media
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Tested up to: 6.7
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_REPLACE_MEDIA_VERSION', '1.0.0' );
define( 'WP_REPLACE_MEDIA_PLUGIN_FILE', __FILE__ );
define( 'WP_REPLACE_MEDIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_REPLACE_MEDIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'init' ] );
