<?php
/**
 * CDN integration service.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

/**
 * CDN class.
 *
 * Handles CDN cache purge integrations for supported hosting platforms.
 *
 * @since 1.0.0
 */
class CDN {

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {

		add_action( 'wp_replace_media_file_replaced', [ $this, 'purge_attachment_urls' ], 10, 3 );
	}

	/**
	 * Purges attachment URLs from supported CDN providers.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file_path     Local attachment path.
	 * @param array  $size_urls     URLs to purge (full + sub-sizes).
	 */
	public function purge_attachment_urls( int $attachment_id, string $file_path, array $size_urls ): void {

		unset( $attachment_id, $file_path );

		$urls = $this->normalize_urls( $size_urls );
		if ( ! empty( $urls ) && is_array( $urls ) ) {
			$this->purge_wpvip_urls( $urls );
		}
	}

	/**
	 * Purges URLs through WPVIP edge cache integration.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,string> $urls URLs to purge.
	 */
	private function purge_wpvip_urls( array $urls ): void {

		if ( ! function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
			return;
		}

		foreach ( $urls as $url ) {
			\wpcom_vip_purge_edge_cache_for_url( $url );
		}
	}

	/**
	 * Sanitizes URL list and removes duplicates.
	 *
	 * @since 1.0.0
	 *
	 * @param array $size_urls Raw URL list.
	 *
	 * @return array<int,string>
	 */
	private function normalize_urls( array $size_urls ): array {

		$urls = [];

		foreach ( $size_urls as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$sanitized_url = esc_url_raw( $url );
			if ( '' === $sanitized_url ) {
				continue;
			}

			$urls[] = $sanitized_url;
		}

		return array_values( array_unique( $urls ) );
	}
}
