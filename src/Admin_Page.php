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
	 * Registered admin page hook suffix.
	 *
	 * @var string
	 */
	private string $page_hook = '';

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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'add_meta_boxes_attachment', [ $this, 'add_attachment_metabox' ] );
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_grid_modal_field' ], 10, 2 );
		add_filter( 'media_row_actions', [ $this, 'add_media_row_action' ], 10, 2 );
	}

	/**
	 * Builds the replace page URL for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string
	 */
	private function get_replace_page_url( int $attachment_id ): string {

		$nonce = wp_create_nonce( 'wp_replace_media_open_' . $attachment_id );

		return (string) add_query_arg(
			[
				'page'       => 'wp-replace-media',
				'attachment' => $attachment_id,
				'_wpnonce'   => $nonce,
			],
			admin_url( 'upload.php' )
		);
	}

	/**
	 * Registers the hidden submenu under Media for Replace Media.
	 */
	public function register_submenu(): void {

		$this->page_hook = add_submenu_page(
			'upload.php',
			__( 'Replace Media', 'wp-replace-media' ),
			__( 'Replace Media', 'wp-replace-media' ),
			'upload_files',
			'wp-replace-media',
			[ $this, 'render' ]
		);

		if ( $this->page_hook ) {
			add_action( 'load-' . $this->page_hook, [ $this, 'handle_page_load' ] );
		}

		// Hide submenu from navigation; accessible via row action only.
		remove_submenu_page( 'upload.php', 'wp-replace-media' );
	}

	/**
	 * Enqueues plugin assets only on the replace page.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function enqueue_assets( string $hook ): void {

		if ( $this->page_hook !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-replace-media-admin',
			WP_REPLACE_MEDIA_PLUGIN_URL . 'assets/css/replace-media.css',
			[],
			WP_REPLACE_MEDIA_VERSION
		);
	}

	/**
	 * Handles request-level revision actions before page output begins.
	 */
	public function handle_page_load(): void {

		$attachment_id = filter_input( INPUT_GET, 'attachment', FILTER_VALIDATE_INT );
		$action        = sanitize_key( (string) filter_input( INPUT_GET, 'wrm_action', FILTER_SANITIZE_SPECIAL_CHARS ) );

		if ( ! $attachment_id || ! in_array( $action, [ 'restore', 'delete_backup' ], true ) ) {
			return;
		}

		$revision_id = filter_input( INPUT_GET, 'revision_id', FILTER_VALIDATE_INT );
		$nonce       = sanitize_text_field( wp_unslash( (string) filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_SPECIAL_CHARS ) ) );

		if ( ! $revision_id || ! wp_verify_nonce( $nonce, 'wrm_revision_action_' . $attachment_id . '_' . $revision_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'wp-replace-media' ) );
		}

		if ( ! Replacer::current_user_can_replace( $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to replace this file.', 'wp-replace-media' ) );
		}

		$result = 'restore' === $action
			? $this->replacer->restore_revision( $attachment_id, $revision_id )
			: $this->replacer->delete_revision_backup( $attachment_id, $revision_id );

		$redirect_url = add_query_arg(
			[
				'tab'             => 'revisions',
				'wrm_notice_type' => isset( $result['type'] ) ? sanitize_key( (string) $result['type'] ) : 'error',
				'wrm_notice'      => isset( $result['message'] ) ? rawurlencode( (string) $result['message'] ) : rawurlencode( __( 'Action failed.', 'wp-replace-media' ) ),
			],
			$this->get_replace_page_url( $attachment_id )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Renders the redirect notice for revision actions.
	 */
	private function render_redirect_notice(): void {

		$notice_type = sanitize_key( (string) filter_input( INPUT_GET, 'wrm_notice_type', FILTER_SANITIZE_SPECIAL_CHARS ) );
		$notice      = sanitize_text_field( wp_unslash( (string) filter_input( INPUT_GET, 'wrm_notice', FILTER_SANITIZE_SPECIAL_CHARS ) ) );

		if ( ! $notice ) {
			return;
		}

		printf(
			'<div class="notice %1$s"><p>%2$s</p></div>',
			'success' === $notice_type ? 'notice-success' : 'notice-error',
			esc_html( rawurldecode( $notice ) )
		);
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

		if ( 'attachment' !== $post->post_type ) {
			return $actions;
		}

		if ( ! Replacer::current_user_can_replace( (int) $post->ID ) ) {
			return $actions;
		}

		if ( ! Replacer::is_allowed_mime_type( get_post_mime_type( $post->ID ) ) ) {
			return $actions;
		}

		$url = $this->get_replace_page_url( (int) $post->ID );

		$actions['wp-replace-media'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Replace', 'wp-replace-media' )
		);

		return $actions;
	}

	/**
	 * Registers the attachment edit screen meta box entry point.
	 *
	 * @param WP_Post $post Attachment post.
	 */
	public function add_attachment_metabox( WP_Post $post ): void {

		if ( ! Replacer::current_user_can_replace( (int) $post->ID ) ) {
			return;
		}

		if ( ! Replacer::is_allowed_mime_type( (string) get_post_mime_type( $post->ID ) ) ) {
			return;
		}

		add_meta_box(
			'wp-replace-media-metabox',
			esc_html__( 'Replace Media', 'wp-replace-media' ),
			[ $this, 'render_attachment_metabox' ],
			'attachment',
			'side',
			'high'
		);
	}

	/**
	 * Renders the attachment edit-screen meta box.
	 *
	 * @param WP_Post $post Attachment post.
	 */
	public function render_attachment_metabox( WP_Post $post ): void {

		$url = $this->get_replace_page_url( (int) $post->ID );

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Replace Media', 'wp-replace-media' )
		);
	}

	/**
	 * Adds a replace button in the media grid modal details form.
	 *
	 * @param array<string,mixed> $form_fields Existing form fields.
	 * @param WP_Post             $post        Attachment post.
	 *
	 * @return array<string,mixed>
	 */
	public function add_grid_modal_field( array $form_fields, WP_Post $post ): array {

		if ( ! Replacer::current_user_can_replace( (int) $post->ID ) ) {
			return $form_fields;
		}

		if ( ! Replacer::is_allowed_mime_type( (string) get_post_mime_type( $post->ID ) ) ) {
			return $form_fields;
		}

		$url = $this->get_replace_page_url( (int) $post->ID );

		$form_fields['wp_replace_media'] = [
			'label' => esc_html__( 'Replace Media', 'wp-replace-media' ),
			'input' => 'html',
			'html'  => sprintf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Replace', 'wp-replace-media' )
			),
		];

		return $form_fields;
	}

	/**
	 * Renders the Replace Media submenu page.
	 */
	public function render(): void {

		$attachment_id = filter_input( INPUT_GET, 'attachment', FILTER_VALIDATE_INT );

		$nonce = sanitize_text_field( wp_unslash( (string) filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_SPECIAL_CHARS ) ) );

		$tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS ) );

		$tab = in_array( $tab, [ 'upload', 'revisions' ], true ) ? $tab : 'upload';

		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'Invalid request.', 'wp-replace-media' ) );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_replace_media_open_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'wp-replace-media' ) );
		}

		if ( ! Replacer::current_user_can_replace( $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to replace this file.', 'wp-replace-media' ) );
		}

		// Get attachment MIME type.
		$attachment = get_post( $attachment_id );
		$mime_type  = $attachment ? get_post_mime_type( $attachment ) : '';

		// Check if attachment MIME type is allowed for replacement.
		if ( ! Replacer::is_allowed_mime_type( $mime_type ) ) {
			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Replace Media', 'wp-replace-media' ); ?></h1>
				<div class="notice notice-error"><p>
					<?php
					echo esc_html__( 'This file type cannot be replaced. Only images and PDFs are supported.', 'wp-replace-media' );
					?>
				</p></div>
			</div>
			<?php
			return;
		}

		$attached_file = (string) get_attached_file( $attachment_id );
		$file_size     = __( 'Unknown', 'wp-replace-media' );
		$meta          = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $meta ) && isset( $meta['filesize'] ) ) {
			$file_size = size_format( (int) $meta['filesize'] );
		}

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

		if ( empty( $attached_file ) ) {
			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Replace Media', 'wp-replace-media' ); ?></h1>
				<p><?php echo esc_html__( 'No file is currently linked to this attachment.', 'wp-replace-media' ); ?></p>
			</div>
			<?php
			return;
		}

		$base_url = $this->get_replace_page_url( $attachment_id );

		$uploaded_at = get_the_date( get_option( 'date_format' ), $attachment_id );
		$file_name   = wp_basename( $attached_file );
		$title       = get_the_title( $attachment_id );
		?>
		<div class="wrap wp-replace-media-wrap">
			<p>
				<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>">&larr; <?php echo esc_html__( 'Back to Media Library', 'wp-replace-media' ); ?></a>
			</p>

			<h1>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: attachment title. */
						__( 'Replace Media - %s', 'wp-replace-media' ),
						$title ? $title : $file_name
					)
				);
				?>
			</h1>

			<div class="postbox wp-replace-media-summary">
				<div class="inside">
					<div class="wp-replace-media-summary__preview">
						<?php if ( str_starts_with( (string) $mime_type, 'image/' ) ) : ?>
							<?php echo wp_kses_post( wp_get_attachment_image( $attachment_id, 'thumbnail', true ) ); ?>
						<?php else : ?>
							<img src="<?php echo esc_url( wp_mime_type_icon( $attachment_id ) ); ?>" alt="" width="80" height="80" />
						<?php endif; ?>
					</div>
					<div class="wp-replace-media-summary__meta">
						<p><strong><?php echo esc_html( $file_name ); ?></strong></p>
						<?php /* translators: %s: MIME type value. */ ?>
						<p><?php echo esc_html( sprintf( __( 'Type: %s', 'wp-replace-media' ), $mime_type ? $mime_type : __( 'Unknown', 'wp-replace-media' ) ) ); ?></p>
						<?php /* translators: %s: Human-readable file size. */ ?>
						<p><?php echo esc_html( sprintf( __( 'Size: %s', 'wp-replace-media' ), $file_size ) ); ?></p>
						<?php /* translators: %s: Human-readable upload date. */ ?>
						<p><?php echo esc_html( sprintf( __( 'Uploaded: %s', 'wp-replace-media' ), $uploaded_at ? $uploaded_at : __( 'Unknown', 'wp-replace-media' ) ) ); ?></p>
					</div>
				</div>
			</div>

			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Replace media tabs', 'wp-replace-media' ); ?>">
				<a class="nav-tab <?php echo 'upload' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'upload' ], $base_url ) ); ?>"><?php echo esc_html__( 'Upload', 'wp-replace-media' ); ?></a>
				<a class="nav-tab <?php echo 'revisions' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'revisions' ], $base_url ) ); ?>"><?php echo esc_html__( 'Revisions', 'wp-replace-media' ); ?></a>
			</nav>

			<div id="wp-replace-media-notice-area"></div>
			<?php
			$this->render_redirect_notice();
			?>

			<?php if ( 'revisions' === $tab ) : ?>
				<?php
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				$revisions_table = new List_Table_Revisions( $attachment_id, (string) add_query_arg( [ 'tab' => 'revisions' ], $base_url ) );
				$revisions_table->prepare_items();
				$revisions_table->display();
				?>
			<?php else : ?>
				<form id="wp-replace-media-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_replace_media_submit', 'wp_replace_media_nonce' ); ?>
					<div class="drag-drop" id="wp-replace-media-dropzone">
						<div class="drag-drop-inside">
							<p class="drag-drop-info"><?php echo esc_html__( 'Drop replacement file here', 'wp-replace-media' ); ?></p>
							<p><?php echo esc_html__( 'or', 'wp-replace-media' ); ?></p>
							<p>
								<label for="wp-replace-media-file" class="screen-reader-text"><?php echo esc_html__( 'Select replacement file', 'wp-replace-media' ); ?></label>
								<input type="file" id="wp-replace-media-file" name="wp_replace_media_file" accept="<?php echo esc_attr( $mime_type ? $mime_type : '' ); ?>" required />
							</p>
						</div>
					</div>

					<p>
						<input type="submit" class="button button-primary" value="<?php echo esc_attr__( 'Replace File', 'wp-replace-media' ); ?>" />
					</p>

					<p class="description"><?php echo esc_html__( 'A backup is created automatically before every replacement.', 'wp-replace-media' ); ?></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
