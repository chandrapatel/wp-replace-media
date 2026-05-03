<?php
/**
 * Renders the Replace Media admin page and registers the Media row action.
 *
 * @package WP_Replace_Media
 */

declare( strict_types=1 );

namespace WP_Replace_Media;

use WP_Post;

/**
 * Admin_Page class.
 *
 * Owns the hidden submenu under Media, the "Replace" row action, and the
 * Replace Media page rendering. Delegates the actual replacement work to
 * a Replacer instance.
 */
class Admin_Page {

	/**
	 * Replacer service.
	 *
	 * @var Replacer
	 */
	private Replacer $replacer;

	/**
	 * Constructor.
	 *
	 * @param Replacer $replacer Replacer service.
	 */
	public function __construct( Replacer $replacer ) {
		$this->replacer = $replacer;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {

		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_filter( 'media_row_actions', [ $this, 'add_media_row_action' ], 10, 2 );
	}

	/**
	 * Registers the hidden submenu under Media for Replace Media.
	 */
	public function register_submenu(): void {

		add_submenu_page(
			'upload.php',
			__( 'Replace Media', 'wp-replace-media' ),
			__( 'Replace Media', 'wp-replace-media' ),
			'upload_files',
			'wp-replace-media',
			[ $this, 'render' ]
		);

		// Hide submenu from navigation; accessible via row action only.
		remove_submenu_page( 'upload.php', 'wp-replace-media' );
	}

	/**
	 * Adds the "Replace" quick action to Media list rows.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Attachment post.
	 *
	 * @return array
	 */
	public function add_media_row_action( array $actions, WP_Post $post ): array {

		if ( 'attachment' !== $post->post_type || ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}

		$nonce = wp_create_nonce( 'wp_replace_media_open_' . $post->ID );
		$url   = add_query_arg(
			[
				'page'       => 'wp-replace-media',
				'attachment' => (int) $post->ID,
				'_wpnonce'   => $nonce,
			],
			admin_url( 'upload.php' )
		);

		$actions['wp-replace-media'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Replace', 'wp-replace-media' )
		);

		return $actions;
	}

	/**
	 * Renders the Replace Media submenu page.
	 */
	public function render(): void {

		$attachment_id = filter_input( INPUT_GET, 'attachment', FILTER_VALIDATE_INT );
		$nonce         = sanitize_text_field( wp_unslash( filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_SPECIAL_CHARS ) ) );

		if ( ! $attachment_id || ! wp_verify_nonce( $nonce, 'wp_replace_media_open_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'wp-replace-media' ) );
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to replace this file.', 'wp-replace-media' ) );
		}

		$attachment    = get_post( $attachment_id );
		$attached_file = get_attached_file( $attachment_id );
		$mime_type     = $attachment ? get_post_mime_type( $attachment ) : '';
		$file_size     = ( $attached_file && file_exists( $attached_file ) ) ? size_format( filesize( $attached_file ) ) : __( 'Unknown', 'wp-replace-media' );

		if (
			isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
			isset( $_POST['wp_replace_media_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_replace_media_nonce'] ) ), 'wp_replace_media_submit' )
		) {
			$notice = $this->replacer->process_submission( (int) $attachment_id, (string) $attached_file, (string) $mime_type );

			if ( ! empty( $notice['type'] ) ) {
				printf(
					'<div class="notice %1$s"><p>%2$s</p></div>',
					'success' === $notice['type'] ? 'notice-success' : 'notice-error',
					esc_html( $notice['message'] )
				);
			}
		}

		if ( ! $attached_file ) {
			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Replace Media', 'wp-replace-media' ); ?></h1>
				<p><?php echo esc_html__( 'No file is currently linked to this attachment.', 'wp-replace-media' ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Replace Media', 'wp-replace-media' ); ?></h1>

			<p>
				<strong><?php echo esc_html__( 'Current file', 'wp-replace-media' ); ?>:</strong>
				<?php echo esc_html( basename( $attached_file ) ); ?>
			</p>
			<p>
				<strong><?php echo esc_html__( 'Type', 'wp-replace-media' ); ?>:</strong>
				<?php echo esc_html( $mime_type ? $mime_type : __( 'Unknown', 'wp-replace-media' ) ); ?>
			</p>
			<p>
				<strong><?php echo esc_html__( 'Size', 'wp-replace-media' ); ?>:</strong>
				<?php echo esc_html( $file_size ); ?>
			</p>

			<p>
				<?php echo esc_html__( 'Upload a replacement file that matches the current file type. The existing URL will stay the same.', 'wp-replace-media' ); ?>
			</p>
			<ul class="wp-replace-media-notes">
				<li><?php echo esc_html__( 'The original file name is reused so links remain unchanged.', 'wp-replace-media' ); ?></li>
				<li><?php echo esc_html__( 'Images will regenerate thumbnails after replacement.', 'wp-replace-media' ); ?></li>
				<li><?php echo esc_html__( 'This action cannot be undone automatically, so keep a backup.', 'wp-replace-media' ); ?></li>
			</ul>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'wp_replace_media_submit', 'wp_replace_media_nonce' ); ?>
				<p>
					<label for="wp-replace-media-file" class="screen-reader-text">
						<?php echo esc_html__( 'Select replacement file', 'wp-replace-media' ); ?>
					</label>
					<?php printf( '<input type="file" id="wp-replace-media-file" name="wp_replace_media_file" accept="%s" required />', esc_attr( $mime_type ? $mime_type : '' ) ); ?>
				</p>
				<p>
					<input type="submit" class="button button-primary" value="<?php echo esc_attr__( 'Replace', 'wp-replace-media' ); ?>" />
				</p>
			</form>

		</div>
		<?php
	}
}
