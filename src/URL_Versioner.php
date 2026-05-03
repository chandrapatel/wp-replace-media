<?php
/**
 * Appends a version query argument to attachment URLs after replacement.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

/**
 * URL_Versioner class.
 *
 * Reads the replacement timestamp stored by Replacer and adds it as a
 * `ver` query parameter to attachment URLs, image src arrays, and srcset
 * sources so browsers and CDNs see a fresh URL after a file is replaced.
 */
class URL_Versioner {

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {

		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_attachment_image_src' ], 10, 2 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_attachment_srcset' ], 10, 5 );
	}

	/**
	 * Adds a version query param to the full attachment URL.
	 *
	 * @param string $url     Original attachment URL.
	 * @param int    $post_id Attachment ID.
	 *
	 * @return string
	 */
	public function filter_attachment_url( string $url, int $post_id ): string {

		return $this->add_version_query( $url, $post_id );
	}

	/**
	 * Adds a version query param to attachment image sources (incl. intermediates).
	 *
	 * @param array|false $image         Image data array or false.
	 * @param int         $attachment_id Attachment ID.
	 *
	 * @return array|false
	 */
	public function filter_attachment_image_src( array|false $image, int $attachment_id ): array|false {

		if ( empty( $image ) || ! isset( $image[0] ) ) {
			return $image;
		}

		$image[0] = $this->add_version_query( (string) $image[0], $attachment_id );

		return $image;
	}

	/**
	 * Adds a version query param to each source in srcset arrays.
	 *
	 * @param array  $sources       Srcset sources.
	 * @param array  $size_array    Requested size array.
	 * @param string $image_src     Current image src.
	 * @param array  $image_meta    Image metadata.
	 * @param int    $attachment_id Attachment ID.
	 *
	 * @return array
	 */
	public function filter_attachment_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {

		$version = $this->get_version( $attachment_id );

		if ( null === $version ) {
			return $sources;
		}

		foreach ( $sources as $descriptor => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $descriptor ]['url'] = add_query_arg( 'ver', rawurlencode( $version ), $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Reads the stored replacement timestamp.
	 *
	 * @param int $post_id Attachment ID.
	 *
	 * @return string|null
	 */
	private function get_version( int $post_id ): ?string {

		$version = get_post_meta( $post_id, Replacer::VERSION_META_KEY, true );

		if ( empty( $version ) || ! is_scalar( $version ) ) {
			return null;
		}

		return (string) $version;
	}

	/**
	 * Appends the version query argument when available.
	 *
	 * @param string $url     URL to modify.
	 * @param int    $post_id Attachment ID.
	 *
	 * @return string
	 */
	private function add_version_query( string $url, int $post_id ): string {

		$version = $this->get_version( $post_id );

		if ( null === $version ) {
			return $url;
		}

		return add_query_arg( 'ver', rawurlencode( $version ), $url );
	}
}
