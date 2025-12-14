<?php
/**
 * Plugin Name: WP Replace Media
 * Description: Replace attachment files in-place while keeping URLs and thumbnails intact.
 * Version: 1.0.0
 * Author: Chandra Patel
 * Text Domain: wp-replace-media
 *
 * @package WP_Replace_Media
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_REPLACE_MEDIA_VERSION', '1.0.0' );
define( 'WP_REPLACE_MEDIA_FILE', __FILE__ );
define( 'WP_REPLACE_MEDIA_PATH', plugin_dir_path( __FILE__ ) );

require_once WP_REPLACE_MEDIA_PATH . 'includes/class-wp-replace-media.php';

\WRM\WP_Replace_Media::get_instance();
