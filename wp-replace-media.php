<?php
/**
 * Plugin Name: WP Replace Media
 * Description: Replace attachment files in-place while keeping URLs and thumbnails intact.
 * Version: 1.0.0
 * Author: Chandra Patel
 * Text Domain: wp-replace-media
 * Requires PHP: 8.0
 *
 * @package WP_Replace_Media
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_REPLACE_MEDIA_VERSION', '1.0.0' );
define( 'WP_REPLACE_MEDIA_FILE', __FILE__ );
define( 'WP_REPLACE_MEDIA_PATH', plugin_dir_path( __FILE__ ) );

// Minimum PHP runtime guard.
if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
	// Show admin notice and avoid loading plugin to prevent fatal errors.
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Replace Media requires PHP 8.0 or higher. The plugin is deactivated.', 'wp-replace-media' ) . '</p></div>';
		}
	);
	deactivate_plugins( plugin_basename( __FILE__ ), true );
	return;
}

require_once WP_REPLACE_MEDIA_PATH . 'includes/class-wp-replace-media.php';

\WRM\WP_Replace_Media::get_instance();
