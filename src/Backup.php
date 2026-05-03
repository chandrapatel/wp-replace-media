<?php
/**
 * Backup file lifecycle service.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

use WP_Error;

/**
 * Backup class.
 *
 * @since 1.0.0
 */
class Backup {

	/**
	 * Creates a backup copy of an attachment before replacement.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_path   Original file absolute path.
	 *
	 * @return array<string,int|string>|WP_Error
	 */
	public function create_backup( int $attachment_id, string $source_path ): array|WP_Error {

		$filesystem = $this->get_filesystem();

		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		if ( ! $filesystem->exists( $source_path ) ) {
			return new WP_Error( 'wrm_backup_source_missing', __( 'Original file could not be found for backup.', 'wp-replace-media' ) );
		}

		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'wrm_backup_upload_dir', __( 'Uploads directory is not available.', 'wp-replace-media' ) );
		}

		$filename = wp_basename( $source_path );

		$backup_dir_rel = 'wp-replace-media-backups/' . $attachment_id;

		$backup_uuid = wp_generate_uuid4();

		$backup_file_rel = $backup_dir_rel . '/' . $backup_uuid . '-' . $filename;

		$backup_dir_abs = trailingslashit( $uploads['basedir'] ) . $backup_dir_rel;

		$backup_file_abs = trailingslashit( $uploads['basedir'] ) . $backup_file_rel;

		$directory_prepared = $this->ensure_directory( $filesystem, $backup_dir_abs );

		if ( is_wp_error( $directory_prepared ) ) {
			return $directory_prepared;
		}

		$copied = $filesystem->copy( $source_path, $backup_file_abs, true, FS_CHMOD_FILE );
		if ( ! $copied ) {
			return new WP_Error( 'wrm_backup_copy_failed', __( 'Backup failed, replacement was aborted.', 'wp-replace-media' ) );
		}

		$backup_size   = (int) $filesystem->size( $backup_file_abs );
		$original_size = (int) $filesystem->size( $source_path );

		return [
			'backup_path'     => $backup_file_rel,
			'backup_size'     => max( 0, $backup_size ),
			'filename'        => $filename,
			'original_size'   => max( 0, $original_size ),
			'backup_abs_path' => $backup_file_abs,
		];
	}

	/**
	 * Builds an absolute backup path from a relative backup path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Relative path from uploads basedir.
	 *
	 * @return string|WP_Error
	 */
	public function absolute_path_from_relative( string $relative_path ): string|WP_Error {

		$uploads = wp_upload_dir();

		if ( empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'wrm_backup_upload_dir', __( 'Uploads directory is not available.', 'wp-replace-media' ) );
		}

		return trailingslashit( $uploads['basedir'] ) . ltrim( $relative_path, '/' );
	}

	/**
	 * Reads backup file contents from a relative backup path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Relative backup path.
	 *
	 * @return string|WP_Error
	 */
	public function read_backup( string $relative_path ): string|WP_Error {

		$filesystem = $this->get_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$absolute_path = $this->absolute_path_from_relative( $relative_path );
		if ( is_wp_error( $absolute_path ) ) {
			return $absolute_path;
		}

		$contents = $filesystem->get_contents( $absolute_path );
		if ( false === $contents ) {
			return new WP_Error( 'wrm_backup_read', __( 'Could not read the selected backup file.', 'wp-replace-media' ) );
		}

		return $contents;
	}

	/**
	 * Deletes a backup file using its relative path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Relative backup path.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_backup( string $relative_path ): bool|WP_Error {

		$filesystem = $this->get_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$absolute_path = $this->absolute_path_from_relative( $relative_path );
		if ( is_wp_error( $absolute_path ) ) {
			return $absolute_path;
		}

		if ( ! $filesystem->exists( $absolute_path ) ) {
			return true;
		}

		if ( ! $filesystem->delete( $absolute_path, false, 'f' ) ) {
			return new WP_Error( 'wrm_backup_delete', __( 'Could not delete the backup file.', 'wp-replace-media' ) );
		}

		return true;
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

	/**
	 * Ensures a directory exists through WP_Filesystem.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Filesystem_Base $filesystem Filesystem instance.
	 * @param string              $directory  Absolute directory path.
	 *
	 * @return true|WP_Error
	 */
	private function ensure_directory( $filesystem, string $directory ): bool|WP_Error {

		if ( $filesystem->is_dir( $directory ) ) {
			return true;
		}

		$created = wp_mkdir_p( $directory );
		if ( ! $created || ! $filesystem->is_dir( $directory ) ) {
			return new WP_Error( 'wrm_backup_dir', __( 'Could not create the backup directory.', 'wp-replace-media' ) );
		}

		return true;
	}
}
