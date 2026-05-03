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
 */
class Plugin {

	/**
	 * Singleton instance of the plugin.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Initialise the plugin (called once on plugins_loaded).
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
	 */
	private function setup(): void {

		( new Admin_Page( new Replacer() ) )->register();
		( new URL_Versioner() )->register();
	}
}
