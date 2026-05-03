<?php
/**
 * Database service for revision storage.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

/**
 * DB class.
 */
class DB {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

	/**
	 * Returns the revisions table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'wrm_revisions';
	}

	/**
	 * Creates/updates the revisions table schema.
	 */
	public static function create_table(): void {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			replaced_at DATETIME NOT NULL,
			filename VARCHAR(255) NOT NULL,
			filesize BIGINT(20) UNSIGNED NOT NULL,
			mime_type VARCHAR(100) NOT NULL,
			backup_path VARCHAR(500) NOT NULL,
			backup_size BIGINT(20) UNSIGNED NOT NULL,
			is_backup_deleted TINYINT(1) NOT NULL DEFAULT 0,
			restored_from_id BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY restored_from_id (restored_from_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Inserts a revision record.
	 *
	 * @param array<string,mixed> $data Revision data.
	 *
	 * @return int
	 */
	public static function insert_revision( array $data ): int {

		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			[
				'attachment_id'     => (int) $data['attachment_id'],
				'user_id'           => (int) $data['user_id'],
				'replaced_at'       => (string) $data['replaced_at'],
				'filename'          => (string) $data['filename'],
				'filesize'          => (int) $data['filesize'],
				'mime_type'         => (string) $data['mime_type'],
				'backup_path'       => (string) $data['backup_path'],
				'backup_size'       => (int) $data['backup_size'],
				'is_backup_deleted' => isset( $data['is_backup_deleted'] ) ? (int) $data['is_backup_deleted'] : 0,
				'restored_from_id'  => isset( $data['restored_from_id'] ) ? (int) $data['restored_from_id'] : null,
			],
			[
				'%d',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
			]
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Gets revision rows for a single attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_revisions_for_attachment( int $attachment_id ): array {

		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE attachment_id = %d ORDER BY id DESC',
				$attachment_id
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Gets a single revision by ID.
	 *
	 * @param int $revision_id Revision ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_revision( int $revision_id ): ?array {

		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $revision_id ),
			ARRAY_A
		);

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Gets a revision by ID limited to a single attachment.
	 *
	 * @param int $revision_id    Revision ID.
	 * @param int $attachment_id  Attachment ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_revision_for_attachment( int $revision_id, int $attachment_id ): ?array {

		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE id = %d AND attachment_id = %d',
				$revision_id,
				$attachment_id
			),
			ARRAY_A
		);

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Marks a revision backup as deleted.
	 *
	 * @param int $revision_id Revision ID.
	 *
	 * @return bool
	 */
	public static function mark_backup_deleted( int $revision_id ): bool {

		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(),
			[ 'is_backup_deleted' => 1 ],
			[ 'id' => $revision_id ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * Checks if an attachment has at least one non-deleted backup.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public static function has_active_backup( int $attachment_id ): bool {

		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM ' . self::table_name() . ' WHERE attachment_id = %d AND is_backup_deleted = 0',
				$attachment_id
			)
		);

		return (int) $count > 0;
	}
	// phpcs:enable
}
