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
 *
 * @since 1.0.0
 */
class Replacer {

	/**
	 * Post meta key for the replacement timestamp (UTC).
	 *
	 * @since 1.0.0
	 */
	public const VERSION_META_KEY = '_wrm_replaced_at';

	/**
	 * Post meta key for quick backup availability checks.
	 *
	 * @since 1.0.0
	 */
	public const HAS_BACKUP_META_KEY = '_wrm_has_backup';

	/**
	 * Backup service.
	 *
	 * @since 1.0.0
	 *
	 * @var Backup
	 */
	private Backup $backup;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Backup|null $backup Backup service.
	 */
	public function __construct( ?Backup $backup = null ) {
		$this->backup = $backup ?? new Backup();
	}

	/**
	 * Processes a submission from the Replace Media page.
	 *
	 * @since 1.0.0
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

		if ( ! self::current_user_can_replace( $post_id ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'You are not allowed to replace this file.', 'wp-replace-media' ),
			];
		}

		// Check if MIME type is allowed.
		if ( ! self::is_allowed_mime_type( $existing_mime ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'This file type cannot be replaced. Only images and PDFs are supported.', 'wp-replace-media' ),
			];
		}

		$upload_file = $_FILES['wp_replace_media_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! empty( $upload_file['error'] ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'There was an error uploading the replacement file.', 'wp-replace-media' ),
			];
		}

		$temp_file = (string) $upload_file['tmp_name'];

		if ( empty( $temp_file ) || ! is_uploaded_file( $temp_file ) ) {
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

		/**
		 * Fires before a media file is replaced.
		 *
		 * Callbacks can call wp_die() to abort the operation.
		 *
		 * @since 1.0.0
		 *
		 * @param int $attachment_id The attachment ID being replaced.
		 */
		do_action( 'wp_replace_media_pre_replace', $post_id );

		$backup = $this->backup->create_backup( $post_id, $existing_file );
		if ( is_wp_error( $backup ) ) {
			return [
				'type'    => 'error',
				'message' => $backup->get_error_message(),
			];
		}

		$is_replaced = $this->replace_file_contents( $existing_file, $temp_file );

		if ( is_wp_error( $is_replaced ) ) {
			return [
				'type'    => 'error',
				'message' => $is_replaced->get_error_message(),
			];
		}

		// Build list of size URLs for CDN purge.
		$size_urls = $this->get_attachment_size_urls( $post_id );

		/**
		 * Fires after a media file is replaced.
		 *
		 * Fires after the file is written and before metadata regeneration.
		 * Use this hook to purge CDN caches or sync files to remote storage.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $attachment_id The attachment ID.
		 * @param string $file_path     The path to the attachment file.
		 * @param array  $size_urls     Array of URLs for all attachment sizes (full + sub-sizes).
		 */
		do_action( 'wp_replace_media_file_replaced', $post_id, $existing_file, $size_urls );

		$this->refresh_attachment_metadata( $post_id, $existing_file, $existing_mime );

		$this->update_modified_dates( $post_id );

		$revision_id = DB::insert_revision(
			[
				'attachment_id' => $post_id,
				'user_id'       => get_current_user_id(),
				'replaced_at'   => gmdate( 'Y-m-d H:i:s' ),
				'filename'      => (string) $backup['filename'],
				'filesize'      => (int) $backup['original_size'],
				'mime_type'     => $existing_mime,
				'backup_path'   => (string) $backup['backup_path'],
				'backup_size'   => (int) $backup['backup_size'],
			]
		);

		if ( $revision_id < 1 ) {
			return [
				'type'    => 'error',
				'message' => __( 'File replaced, but the revision log could not be saved.', 'wp-replace-media' ),
			];
		}

		// Save replacement timestamp in UTC for cache-busting URLs.
		$current_utc_time = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		update_post_meta( $post_id, self::VERSION_META_KEY, $current_utc_time->getTimestamp() );
		update_post_meta( $post_id, self::HAS_BACKUP_META_KEY, 1 );

		/**
		 * Fires after a media file replacement is complete.
		 *
		 * Fires after all database and metadata updates are done.
		 *
		 * @since 1.0.0
		 *
		 * @param int $attachment_id The attachment ID.
		 * @param int $revision_id   The revision row ID.
		 */
		do_action( 'wp_replace_media_completed', $post_id, $revision_id );

		return [
			'type'    => 'success',
			'message' => __( 'Media file replaced successfully.', 'wp-replace-media' ),
		];
	}

	/**
	 * Restores an attachment from a saved revision backup.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $revision_id   Source revision ID.
	 *
	 * @return array{type:string,message:string}
	 */
	public function restore_revision( int $attachment_id, int $revision_id ): array {

		if ( ! self::current_user_can_replace( $attachment_id ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'You are not allowed to restore this file.', 'wp-replace-media' ),
			];
		}

		$revision = DB::get_revision_for_attachment( $revision_id, $attachment_id );
		if ( ! $revision ) {
			return [
				'type'    => 'error',
				'message' => __( 'Selected revision could not be found.', 'wp-replace-media' ),
			];
		}

		if ( ! empty( $revision['is_backup_deleted'] ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'This revision backup was deleted and cannot be restored.', 'wp-replace-media' ),
			];
		}

		$backup_contents = $this->backup->read_backup( (string) $revision['backup_path'] );
		if ( is_wp_error( $backup_contents ) ) {
			return [
				'type'    => 'error',
				'message' => $backup_contents->get_error_message(),
			];
		}

		$current_file = (string) get_attached_file( $attachment_id );
		if ( '' === $current_file ) {
			return [
				'type'    => 'error',
				'message' => __( 'No file is currently linked to this attachment.', 'wp-replace-media' ),
			];
		}

		$current_mime = (string) get_post_mime_type( $attachment_id );

		do_action( 'wp_replace_media_pre_replace', $attachment_id );

		$backup = $this->backup->create_backup( $attachment_id, $current_file );
		if ( is_wp_error( $backup ) ) {
			return [
				'type'    => 'error',
				'message' => $backup->get_error_message(),
			];
		}

		$written = $this->write_file_contents( $current_file, $backup_contents );
		if ( is_wp_error( $written ) ) {
			return [
				'type'    => 'error',
				'message' => $written->get_error_message(),
			];
		}

		$size_urls = $this->get_attachment_size_urls( $attachment_id );
		do_action( 'wp_replace_media_file_replaced', $attachment_id, $current_file, $size_urls );

		$this->refresh_attachment_metadata( $attachment_id, $current_file, $current_mime );
		$this->update_modified_dates( $attachment_id );

		$new_revision_id = DB::insert_revision(
			[
				'attachment_id'    => $attachment_id,
				'user_id'          => get_current_user_id(),
				'replaced_at'      => gmdate( 'Y-m-d H:i:s' ),
				'filename'         => (string) $backup['filename'],
				'filesize'         => (int) $backup['original_size'],
				'mime_type'        => $current_mime,
				'backup_path'      => (string) $backup['backup_path'],
				'backup_size'      => (int) $backup['backup_size'],
				'restored_from_id' => $revision_id,
			]
		);

		if ( $new_revision_id < 1 ) {
			return [
				'type'    => 'error',
				'message' => __( 'File restored, but the revision log could not be saved.', 'wp-replace-media' ),
			];
		}

		$current_utc_time = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		update_post_meta( $attachment_id, self::VERSION_META_KEY, $current_utc_time->getTimestamp() );
		update_post_meta( $attachment_id, self::HAS_BACKUP_META_KEY, 1 );

		do_action( 'wp_replace_media_completed', $attachment_id, $new_revision_id );

		return [
			'type'    => 'success',
			'message' => __( 'Revision restored successfully.', 'wp-replace-media' ),
		];
	}

	/**
	 * Deletes a backup file for a revision while keeping the revision row.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $revision_id   Revision ID.
	 *
	 * @return array{type:string,message:string}
	 */
	public function delete_revision_backup( int $attachment_id, int $revision_id ): array {

		if ( ! self::current_user_can_replace( $attachment_id ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'You are not allowed to delete this backup.', 'wp-replace-media' ),
			];
		}

		$revision = DB::get_revision_for_attachment( $revision_id, $attachment_id );
		if ( ! $revision ) {
			return [
				'type'    => 'error',
				'message' => __( 'Selected revision could not be found.', 'wp-replace-media' ),
			];
		}

		if ( ! empty( $revision['is_backup_deleted'] ) ) {
			return [
				'type'    => 'success',
				'message' => __( 'Backup was already deleted.', 'wp-replace-media' ),
			];
		}

		$deleted = $this->backup->delete_backup( (string) $revision['backup_path'] );
		if ( is_wp_error( $deleted ) ) {
			return [
				'type'    => 'error',
				'message' => $deleted->get_error_message(),
			];
		}

		if ( ! DB::mark_backup_deleted( $revision_id ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'Backup file was deleted, but revision state could not be updated.', 'wp-replace-media' ),
			];
		}

		update_post_meta( $attachment_id, self::HAS_BACKUP_META_KEY, DB::has_active_backup( $attachment_id ) ? 1 : 0 );

		return [
			'type'    => 'success',
			'message' => __( 'Backup deleted successfully.', 'wp-replace-media' ),
		];
	}

	/**
	 * Overwrites the destination file with the uploaded contents using WP_Filesystem.
	 *
	 * @since 1.0.0
	 *
	 * @param string $destination Existing attachment path.
	 * @param string $temp_file   Temporary uploaded file path.
	 *
	 * @return bool|WP_Error
	 */
	private function replace_file_contents( string $destination, string $temp_file ): bool|WP_Error {

		$wp_filesystem = $this->get_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
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
	 * Writes string content to a destination file via WP_Filesystem.
	 *
	 * @since 1.0.0
	 *
	 * @param string $destination Destination path.
	 * @param string $content     File contents.
	 *
	 * @return true|WP_Error
	 */
	private function write_file_contents( string $destination, string $content ): bool|WP_Error {

		$wp_filesystem = $this->get_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		if ( ! $wp_filesystem->put_contents( $destination, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'wrm_write_error', __( 'Failed to restore the selected backup file.', 'wp-replace-media' ) );
		}

		return true;
	}

	/**
	 * Checks if the MIME type is allowed for replacement.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mime_type MIME type to check.
	 *
	 * @return bool
	 */
	public static function is_allowed_mime_type( string $mime_type ): bool {

		if ( ! $mime_type ) {
			return false;
		}

		/**
		 * Filters the allowed MIME type prefixes for replacement.
		 *
		 * @since 1.0.0
		 *
		 * @param array $allowed_types Array of MIME type prefixes to allow. Default: [ 'image', 'application/pdf' ].
		 *                              Matches are done via str_starts_with(), so 'image' matches 'image/jpeg', etc.
		 */
		$allowed_types = apply_filters(
			'wp_replace_media_allowed_types',
			[ 'image', 'application/pdf' ]
		);

		// Check if MIME type starts with one of the allowed prefixes or matches exactly.
		foreach ( (array) $allowed_types as $allowed ) {
			if ( str_starts_with( $mime_type, $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if the current user has the capability to replace an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public static function current_user_can_replace( int $attachment_id ): bool {

		/**
		 * Filters the required capability for replacing media.
		 *
		 * @since 1.0.0
		 *
		 * @param string $cap           The required capability. Default 'upload_files'.
		 * @param int    $attachment_id The attachment ID.
		 */
		$required_cap = apply_filters( 'wp_replace_media_capability', 'upload_files', $attachment_id );

		if ( empty( $required_cap ) || ! is_string( $required_cap ) ) {
			return false;
		}

		if ( ! current_user_can( $required_cap ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Gets URLs for all attachment sizes (full and sub-sizes).
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array Array of size URLs.
	 */
	private function get_attachment_size_urls( int $attachment_id ): array {

		$size_urls = [];

		// Full-size URL.
		$full_url = wp_get_attachment_url( $attachment_id );
		if ( $full_url ) {
			$size_urls[] = $full_url;
		}

		// Sub-size URLs.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( isset( $size['url'] ) ) {
					$size_urls[] = $size['url'];
				}
			}
		}

		return $size_urls;
	}

	/**
	 * Regenerates attachment metadata when required.
	 *
	 * @since 1.0.0
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

		$wp_filesystem = $this->get_filesystem();
		if ( ! is_wp_error( $wp_filesystem ) && $wp_filesystem->exists( $primary_path ) ) {
			$metadata['filesize'] = max( 0, (int) $wp_filesystem->size( $primary_path ) );
		}

		wp_update_attachment_metadata( $post_id, $metadata );
	}

	/**
	 * Updates the attachment modified timestamps.
	 *
	 * @since 1.0.0
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

	/**
	 * Initialises and returns the global filesystem object.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_Filesystem_Base|WP_Error
	 */
	private function get_filesystem() {

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) && ! WP_Filesystem() ) {
			$creds = request_filesystem_credentials( site_url() );
			if ( false === $creds || ! WP_Filesystem( $creds ) ) {
				return new WP_Error( 'wrm_filesystem_init', __( 'Filesystem access is not available.', 'wp-replace-media' ) );
			}
		}

		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
			return new WP_Error( 'wrm_filesystem_init', __( 'Filesystem access is not available.', 'wp-replace-media' ) );
		}

		return $wp_filesystem;
	}
}
