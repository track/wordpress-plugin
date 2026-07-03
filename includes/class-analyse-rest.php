<?php
/**
 * REST receiver for Analyse → WordPress publishing.
 *
 * @package Analyse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles POST /wp-json/analyse/v1/publish from Analyse.
 */
class Analyse_Rest {

	const SIDELOAD_HOOK = 'analyse_sideload_image';

	/**
	 * Singleton instance.
	 *
	 * @var Analyse_Rest|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return Analyse_Rest
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the REST route and the async image sideload handler.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::SIDELOAD_HOOK, array( $this, 'sideload_featured_image' ), 10, 2 );
	}

	/**
	 * Registers /analyse/v1/publish.
	 */
	public function register_routes() {
		register_rest_route(
			'analyse/v1',
			'/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_publish' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
	}

	/**
	 * Verifies the HMAC signature on the raw request body.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function verify_request( $request ) {
		if ( ! Analyse_Settings::get( 'publish_receive_enabled' ) ) {
			return new WP_Error( 'analyse_disabled', __( 'Publishing from Analyse is disabled in the plugin settings.', 'analyse' ), array( 'status' => 403 ) );
		}

		$secret = Analyse_Settings::get( 'signing_secret' );
		if ( ! $secret ) {
			return new WP_Error( 'analyse_no_secret', __( 'Set a signing secret in Settings → Analyse before publishing.', 'analyse' ), array( 'status' => 403 ) );
		}

		$signature = $request->get_header( 'x-analyse-signature' );
		if ( ! $signature || ! Analyse_Signature::verify( $request->get_body(), $signature, $secret ) ) {
			return new WP_Error( 'analyse_bad_signature', __( 'Invalid signature.', 'analyse' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Creates or updates a post from a blog_post.published payload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_publish( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return new WP_Error( 'analyse_bad_payload', __( 'Malformed payload.', 'analyse' ), array( 'status' => 400 ) );
		}

		// Test deliveries from the integration wizard must not create content.
		if ( ! empty( $payload['test'] ) ) {
			return rest_ensure_response(
				array(
					'ok'   => true,
					'test' => true,
				)
			);
		}

		$data = $payload['data'];
		if ( empty( $data['id'] ) || empty( $data['title'] ) ) {
			return new WP_Error( 'analyse_bad_payload', __( 'Missing post id or title.', 'analyse' ), array( 'status' => 400 ) );
		}

		$analyse_id = (string) $data['id'];
		$content    = $this->markdown_to_html( isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '' );

		$postarr = array(
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_name'    => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'post_content' => $content,
			'post_excerpt' => isset( $data['excerpt'] ) && is_string( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'post_type'    => 'post',
		);

		$existing_id = $this->find_existing_post( $analyse_id );

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_status'] = Analyse_Settings::get( 'default_post_status' );

			$author_id = (int) Analyse_Settings::get( 'default_author_id' );
			if ( $author_id > 0 ) {
				$postarr['post_author'] = $author_id;
			}

			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'analyse_insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		// Marks the post as Analyse-originated; also the sync loop guard.
		update_post_meta( $post_id, '_analyse_post_id', $analyse_id );

		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$names = array_filter( array_map( 'sanitize_text_field', $data['categories'] ) );
			if ( $names ) {
				$category_ids = wp_create_categories( $names, $post_id );
				if ( $category_ids ) {
					wp_set_post_categories( $post_id, $category_ids );
				}
			}
		}

		// Sideload asynchronously: the Analyse dispatcher enforces a 15s
		// timeout on this request and the signed URL stays valid for hours.
		if ( ! empty( $data['featuredImageUrl'] ) && is_string( $data['featuredImageUrl'] ) ) {
			wp_schedule_single_event( time(), self::SIDELOAD_HOOK, array( $post_id, esc_url_raw( $data['featuredImageUrl'] ) ) );
		}

		return rest_ensure_response(
			array(
				'post_id'   => $post_id,
				'url'       => get_permalink( $post_id ),
				'permalink' => get_permalink( $post_id ),
			)
		);
	}

	/**
	 * Finds a previously created post for an Analyse post id.
	 *
	 * @param string $analyse_id Analyse blog post id.
	 * @return int Post id, or 0 when none exists.
	 */
	private function find_existing_post( $analyse_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_analyse_post_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $analyse_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Converts the payload's markdown body to sanitized HTML.
	 *
	 * @param string $markdown Markdown source.
	 * @return string
	 */
	private function markdown_to_html( $markdown ) {
		if ( '' === $markdown ) {
			return '';
		}

		if ( ! class_exists( 'Parsedown' ) ) {
			require_once ANALYSE_PLUGIN_DIR . 'includes/lib/Parsedown.php';
		}

		$parser = new Parsedown();
		$parser->setSafeMode( true );

		return wp_kses_post( $parser->text( $markdown ) );
	}

	/**
	 * Cron handler: downloads the featured image and attaches it to the post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $url     Signed image URL.
	 */
	public function sideload_featured_image( $post_id, $url ) {
		if ( ! get_post( $post_id ) || get_post_thumbnail_id( $post_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $post_id, get_the_title( $post_id ), 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}
}
