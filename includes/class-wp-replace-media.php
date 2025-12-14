<?php
/**
 * Handles the Replace Media workflow.
 *
 * @package WP_Replace_Media
 */

namespace WRM;

use DateTime;
use DateTimeZone;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Replace Media workflow.
 */
class WP_Replace_Media {

	/**
	 * Singleton instance.
	 *
	 * @var WP_Replace_Media|null
	 */
	private static ?WP_Replace_Media $instance = null;

	/**
	 * Messages queued for redirect.
	 *
	 * @var array
	 */
	private array $notices = [];

	/**
	 * Bootstraps the plugin.
	 */
	private function __construct() {

		// Custom Replace Media page and actions.
		add_action( 'admin_menu', [ $this, 'register_replace_media_submenu' ] );
		add_filter( 'media_row_actions', [ $this, 'add_media_row_action' ], 10, 2 );

		// Versioning filters.
		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url_version' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_attachment_image_src' ], 10, 2 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_attachment_srcset' ], 10, 5 );
	}

	/**
	 * Retrieves the singleton instance.
	 *
	 * @return WP_Replace_Media
	 */
	public static function get_instance(): WP_Replace_Media {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hidden submenu under Media for Replace Media.
	 */
	public function register_replace_media_submenu(): void {

		$hook = add_submenu_page(
			'upload.php',
			__( 'Replace Media', 'wp-replace-media' ),
			__( 'Replace Media', 'wp-replace-media' ),
			'upload_files',
			'wp-replace-media',
			[ $this, 'render_replace_media_page' ]
		);

		// Hide submenu from navigation; accessible via row action only.
		remove_submenu_page( 'upload.php', 'wp-replace-media' );

		add_action(
			"load-$hook",
			function () {
				// Reserved for future page-specific setup.
			}
		);
	}

	/**
	 * Adds "Replace" quick action to Media list rows.
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
	 *
	 * @return void
	 */
	public function render_replace_media_page(): void {

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

		// On submit, process replacement and show inline notice.
		if (
			isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
			isset( $_POST['wp_replace_media_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_replace_media_nonce'] ) ), 'wp_replace_media_submit' )
		) {

			$notice = $this->process_replace_submission( $attachment_id, (string) $attached_file, (string) $mime_type );

			if ( ! empty( $notice['type'] ) ) {
				printf( '<div class="notice %1$s"><p>%2$s</p></div>', 'success' === $notice['type'] ? 'notice-success' : 'notice-error', esc_html( $notice['message'] ) );
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

	/**
	 * Processes submission from the Replace Media page.
	 *
	 * @param int    $post_id        Attachment ID.
	 * @param string $existing_file  Existing file path.
	 * @param string $existing_mime  Existing mime type.
	 *
	 * @return array{type:string,message:string}
	 */
	private function process_replace_submission( int $post_id, string $existing_file, string $existing_mime ): array {

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

		$this->refresh_attachment_metadata( $post_id, $existing_file, (string) $existing_mime );

		$this->update_modified_dates( $post_id );

		// Save replacement timestamp in UTC.
		// Used for versioning or cache-busting.
		$current_utc_time = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		update_post_meta( $post_id, '_wp_replace_media_replaced', $current_utc_time->getTimestamp() );

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

		// Initialize WP_Filesystem.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
			$creds = request_filesystem_credentials( site_url() );
			WP_Filesystem( $creds );
		}

		// Read contents from temporary uploaded file.
		$file_contents = $wp_filesystem->get_contents( $temp_file );

		if ( false === $file_contents ) {
			return new WP_Error( 'wrm_read_error', __( 'Failed to read the uploaded file.', 'wp-replace-media' ) );
		}

		// Write contents to existing attachment file.
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
	 *
	 * @return void
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

	/**
	 * Adds version query param to the full attachment URL.
	 *
	 * @param string $url     Original attachment URL.
	 * @param int    $post_id Attachment ID.
	 *
	 * @return string
	 */
	public function filter_attachment_url_version( string $url, int $post_id ): string {

		return $this->add_version_query( $url, $post_id );
	}

	/**
	 * Adds version query param to attachment image sources (includes intermediates).
	 *
	 * @param array|false $image          Image data array or false.
	 * @param int         $attachment_id  Attachment ID.
	 *
	 * @return array|false
	 */
	public function filter_attachment_image_src( array|false $image, int $attachment_id ): array|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( empty( $image ) || ! isset( $image[0] ) ) {
			return $image;
		}

		$image[0] = $this->add_version_query( (string) $image[0], $attachment_id );

		return $image;
	}

	/**
	 * Adds version query param to each source in srcset arrays.
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

		$version = $this->get_replacement_version( $attachment_id );

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
	private function get_replacement_version( int $post_id ): ?string {

		$version = get_post_meta( $post_id, '_wp_replace_media_replaced', true );

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

		$version = $this->get_replacement_version( $post_id );

		if ( null === $version ) {
			return $url;
		}

		return add_query_arg( 'ver', rawurlencode( $version ), $url );
	}
}
