<?php
/**
 * Revisions list table.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

/**
 * Revisions list table class.
 *
 * @since 1.0.0
 */
class List_Table_Revisions extends \WP_List_Table {

	/**
	 * Attachment ID.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Base URL for actions.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $base_url      Base admin URL for action links.
	 */
	public function __construct( int $attachment_id, string $base_url ) {

		$this->attachment_id = $attachment_id;
		$this->base_url      = $base_url;

		parent::__construct(
			[
				'singular' => 'wrm-revision',
				'plural'   => 'wrm-revisions',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Column definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {

		return [
			'id'               => '#',
			'replaced_at'      => esc_html__( 'Date', 'wp-replace-media' ),
			'user_id'          => esc_html__( 'Replaced By', 'wp-replace-media' ),
			'filesize'         => esc_html__( 'File Size', 'wp-replace-media' ),
			'backup_file_url'  => esc_html__( 'Backup File', 'wp-replace-media' ),
			'revision_actions' => esc_html__( 'Actions', 'wp-replace-media' ),
		];
	}

	/**
	 * Prepares table items.
	 *
	 * @since 1.0.0
	 */
	public function prepare_items(): void {

		$this->_column_headers = [ $this->get_columns() ];
		$this->items           = DB::get_revisions_for_attachment( $this->attachment_id );
	}

	/**
	 * Renders default columns.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $item        Row data.
	 * @param string              $column_name Column name.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {

		switch ( $column_name ) {
			case 'id':
				return (string) (int) $item['id'];

			case 'replaced_at':
				$time = strtotime( (string) $item['replaced_at'] . ' UTC' );
				return $time ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) : '';

			case 'user_id':
				$user = get_userdata( (int) $item['user_id'] );
				return $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'wp-replace-media' );

			case 'filesize':
				return esc_html( size_format( (int) $item['filesize'] ) );

			case 'backup_file_url':
				return $this->column_backup_file( $item );

			case 'revision_actions':
				return $this->column_actions( $item );

			default:
				return '';
		}
	}

	/**
	 * Renders backup file column.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $item Row data.
	 *
	 * @return string
	 */
	private function column_backup_file( array $item ): string {

		if ( ! empty( $item['is_backup_deleted'] ) ) {
			$text = esc_html__( 'Backup deleted', 'wp-replace-media' );
		} else {
			$upload_dir = wp_upload_dir();
			$base_url   = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';
			$backup_url = trailingslashit( $base_url ) . ltrim( (string) $item['backup_path'], '/' );
			$text       = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $backup_url ),
				esc_html( (string) $item['filename'] )
			);
		}

		if ( ! empty( $item['restored_from_id'] ) ) {
			/* translators: %d: source revision ID. */
			$restored_note = sprintf( __( 'Restored from #%d', 'wp-replace-media' ), (int) $item['restored_from_id'] );
			$text         .= sprintf(
				'<br><span class="description">%s</span>',
				esc_html( $restored_note )
			);
		}

		return $text;
	}

	/**
	 * Renders row action links.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $item Row data.
	 *
	 * @return string
	 */
	private function column_actions( array $item ): string {

		if ( ! empty( $item['is_backup_deleted'] ) ) {
			return '&mdash;';
		}

		$revision_id = (int) $item['id'];

		$restore_url = wp_nonce_url(
			add_query_arg(
				[
					'wrm_action'  => 'restore',
					'revision_id' => $revision_id,
				],
				$this->base_url
			),
			'wrm_revision_action_' . $this->attachment_id . '_' . $revision_id
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				[
					'wrm_action'  => 'delete_backup',
					'revision_id' => $revision_id,
				],
				$this->base_url
			),
			'wrm_revision_action_' . $this->attachment_id . '_' . $revision_id
		);

		$restore_confirm = esc_attr(
			(string) wp_json_encode( __( 'Restore this revision? This will replace the current attachment file.', 'wp-replace-media' ) )
		);
		$delete_confirm  = esc_attr(
			(string) wp_json_encode( __( 'Delete this backup file? This cannot be undone.', 'wp-replace-media' ) )
		);

		$actions = [
			sprintf( '<a href="%1$s" onclick="return window.confirm( %2$s );">%3$s</a>', esc_url( $restore_url ), $restore_confirm, esc_html__( 'Restore', 'wp-replace-media' ) ),
			sprintf( '<a href="%1$s" onclick="return window.confirm( %2$s );">%3$s</a>', esc_url( $delete_url ), $delete_confirm, esc_html__( 'Delete backup', 'wp-replace-media' ) ),
		];

		return implode( ' &middot; ', $actions );
	}

	/**
	 * Empty state text.
	 *
	 * @since 1.0.0
	 */
	public function no_items(): void {
		echo esc_html__( 'No revisions yet. Revisions are created automatically each time you replace this file.', 'wp-replace-media' );
	}
}
