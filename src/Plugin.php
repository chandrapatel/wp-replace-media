<?php
/**
 * Main plugin bootstrap class.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

/**
 * Plugin class.
 *
 * Single entry point for registering all plugin hooks.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Singleton instance of the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Activation callback.
	 *
	 * @since 1.0.0
	 */
	public static function activate(): void {

		DB::create_table();
	}

	/**
	 * Initialise the plugin (called once on plugins_loaded).
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {

		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->setup();
	}

	/**
	 * Register all feature hooks.
	 *
	 * @since 1.0.0
	 */
	private function setup(): void {

		// Load plugin text domain for i18n.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		( new Admin_Page( new Replacer() ) )->register();
		( new CDN() )->register();
		( new URL_Versioner() )->register();
	}

	/**
	 * Loads the plugin text domain for internationalization.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain(): void {

		load_plugin_textdomain(
			'wp-replace-media',
			false,
			dirname( plugin_basename( WP_REPLACE_MEDIA_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
