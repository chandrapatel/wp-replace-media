<?php
/**
 * File replacement service.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

use DateTime;
use DateTimeZone;
use WP_Error;

/**
 * Replacer class.
 *
 * Validates submissions, overwrites the attachment file via WP_Filesystem,
 * regenerates metadata, updates timestamps, and stores the replacement
 * version used by URL_Versioner.
 */
class Replacer {

	/**
	 * Post meta key for the replacement timestamp (UTC).
	 */
	public const VERSION_META_KEY = '_wp_replace_media_replaced';

	/**
	 * Processes a submission from the Replace Media page.
	 *
	 * @param int    $post_id        Attachment ID.
	 * @param string $existing_file  Existing file path.
	 * @param string $existing_mime  Existing mime type.
	 *
	 * @return array{type:string,message:string}
	 */
	public function process_submission( int $post_id, string $existing_file, string $existing_mime ): array {

		if ( empty( $_FILES['wp_replace_media_file']['name'] ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'Please select a file to upload.', 'wp-replace-media' ),
			];
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'You are not allowed to replace this file.', 'wp-replace-media' ),
			];
		}

		$upload_file = $_FILES['wp_replace_media_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! empty( $upload_file['error'] ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'There was an error uploading the replacement file.', 'wp-replace-media' ),
			];
		}

		$temp_file = $upload_file['tmp_name'];

		if ( ! file_exists( $temp_file ) || ! is_uploaded_file( $temp_file ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'Invalid upload. Please try again.', 'wp-replace-media' ),
			];
		}

		$uploaded_filetype = wp_check_filetype( $upload_file['name'] );

		if ( $existing_mime && ( $uploaded_filetype['type'] !== $existing_mime ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'Please choose a file with the same MIME type as the original.', 'wp-replace-media' ),
			];
		}

		$is_replaced = $this->replace_file_contents( $existing_file, $temp_file );

		if ( is_wp_error( $is_replaced ) ) {
			return [
				'type'    => 'error',
				'message' => $is_replaced->get_error_message(),
			];
		}

		$this->refresh_attachment_metadata( $post_id, $existing_file, $existing_mime );
		$this->update_modified_dates( $post_id );

		// Save replacement timestamp in UTC for cache-busting URLs.
		$current_utc_time = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		update_post_meta( $post_id, self::VERSION_META_KEY, $current_utc_time->getTimestamp() );

		return [
			'type'    => 'success',
			'message' => __( 'Media file replaced successfully.', 'wp-replace-media' ),
		];
	}

	/**
	 * Overwrites the destination file with the uploaded contents using WP_Filesystem.
	 *
	 * @param string $destination Existing attachment path.
	 * @param string $temp_file   Temporary uploaded file path.
	 *
	 * @return bool|WP_Error
	 */
	private function replace_file_contents( string $destination, string $temp_file ): bool|WP_Error {

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
			$creds = request_filesystem_credentials( site_url() );
			WP_Filesystem( $creds );
		}

		$file_contents = $wp_filesystem->get_contents( $temp_file );

		if ( false === $file_contents ) {
			return new WP_Error( 'wrm_read_error', __( 'Failed to read the uploaded file.', 'wp-replace-media' ) );
		}

		$result = $wp_filesystem->put_contents( $destination, $file_contents, FS_CHMOD_FILE );

		if ( ! $result ) {
			return new WP_Error( 'wrm_write_error', __( 'Failed to replace the existing file.', 'wp-replace-media' ) );
		}

		return true;
	}

	/**
	 * Regenerates attachment metadata when required.
	 *
	 * @param int    $post_id      Attachment ID.
	 * @param string $primary_path Full path to the main file.
	 * @param string $mime_type    Attachment MIME type.
	 */
	private function refresh_attachment_metadata( int $post_id, string $primary_path, string $mime_type ): void {

		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( str_contains( $mime_type, 'image/' ) ) {
			$new_metadata = wp_generate_attachment_metadata( $post_id, $primary_path );
			wp_update_attachment_metadata( $post_id, $new_metadata );
			return;
		}

		$metadata = wp_get_attachment_metadata( $post_id );

		if ( ! is_array( $metadata ) ) {
			$metadata = [];
		}

		if ( file_exists( $primary_path ) ) {
			$metadata['filesize'] = (int) filesize( $primary_path );
		}

		wp_update_attachment_metadata( $post_id, $metadata );
	}

	/**
	 * Updates the attachment modified timestamps.
	 *
	 * @param int $post_id Attachment ID.
	 */
	private function update_modified_dates( int $post_id ): void {

		$local_time = current_time( 'mysql' );
		$gmt_time   = current_time( 'mysql', true );

		wp_update_post(
			[
				'ID'                => $post_id,
				'post_modified'     => $local_time,
				'post_modified_gmt' => $gmt_time,
			]
		);
	}
}
