<?php
/**
 * Plugin Name: WP Replace Media
 * Description: Replace attachment files in-place while keeping URLs and thumbnails intact.
 * Version:     1.0.0
 * Author:      Chandra Patel
 * Author URI:  https://chandra.dev
 * Plugin URI:  https://github.com/chandrapatel/wp-replace-media
 * Update URI:  https://github.com/chandrapatel/wp-replace-media
 * License:     GPL-2.0-or-later
 * Text Domain: wp-replace-media
 * Requires at least: 6.0
 * Requires PHP: 8.0
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

add_action( 'plugins_loaded', [ Plugin::class, 'init' ] );
