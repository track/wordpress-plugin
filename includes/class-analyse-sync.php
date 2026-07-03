<?php
/**
 * Syncs WordPress posts to Analyse (WP → Analyse ingestion).
 *
 * @package Analyse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes post publish/update/delete events to the Analyse ingest endpoint.
 */
class Analyse_Sync {

	const CRON_HOOK    = 'analyse_sync_post';
	const MAX_ATTEMPTS = 5;

	/**
	 * Retry delays in seconds, indexed by attempt number.
	 *
	 * @var int[]
	 */
	private static $retry_delays = array( 60, 300, 1800, 1800 );

	/**
	 * Singleton instance.
	 *
	 * @var Analyse_Sync|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return Analyse_Sync
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks post lifecycle events and the async delivery handler.
	 */
	public function register() {
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 10, 4 );
		add_action( 'transition_post_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'on_post_removed' ) );
		add_action( 'before_delete_post', array( $this, 'on_post_removed' ) );
		add_action( self::CRON_HOOK, array( $this, 'deliver' ), 10, 3 );
	}

	/**
	 * Whether syncing applies to this post at all.
	 *
	 * @param WP_Post|null $post Post object.
	 * @return bool
	 */
	private function is_syncable( $post ) {
		if ( ! Analyse_Settings::get( 'ingest_enabled' ) || ! Analyse_Settings::get( 'public_key' ) || ! Analyse_Settings::get( 'signing_secret' ) ) {
			return false;
		}

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return false;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		// Posts that Analyse itself published here must not round-trip back.
		if ( get_post_meta( $post->ID, '_analyse_post_id', true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Queues an upsert when a post is saved as published.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post      $post    Post object.
	 * @param bool         $update  Whether this is an update.
	 * @param WP_Post|null $post_before Post before the update.
	 */
	public function on_post_saved( $post_id, $post, $update, $post_before ) {
		unset( $update, $post_before );

		if ( 'publish' !== $post->post_status || ! $this->is_syncable( $post ) ) {
			return;
		}

		$this->enqueue( $post_id, 'upsert' );
	}

	/**
	 * Queues a delete when a published post is unpublished.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_status_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $old_status || 'publish' === $new_status || 'trash' === $new_status ) {
			// Trash is handled by wp_trash_post; publishes by wp_after_insert_post.
			return;
		}

		if ( ! $this->is_syncable( $post ) ) {
			return;
		}

		$this->enqueue( $post->ID, 'delete' );
	}

	/**
	 * Queues a delete when a post is trashed or permanently deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_post_removed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! $this->is_syncable( $post ) ) {
			return;
		}

		// The post row may be gone when the cron handler runs, so deliver the
		// delete payload built from the still-available data synchronously via
		// an immediate scheduled event carrying the payload itself.
		$payload = $this->build_payload( $post, 'delete' );
		wp_schedule_single_event( time(), self::CRON_HOOK, array( 0, 'delete', array( 'payload' => $payload, 'attempt' => 1 ) ) );
	}

	/**
	 * Schedules an async delivery for a post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $action  "upsert" or "delete".
	 * @param int    $attempt Attempt number (1-based).
	 */
	private function enqueue( $post_id, $action, $attempt = 1 ) {
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $post_id, $action, array( 'attempt' => $attempt ) ) );
	}

	/**
	 * Cron handler: builds the payload and POSTs it to Analyse.
	 *
	 * @param int    $post_id Post id (0 when the payload is embedded in $args).
	 * @param string $action  "upsert" or "delete".
	 * @param array  $args    Extra args: attempt, optional prebuilt payload.
	 */
	public function deliver( $post_id, $action, $args = array() ) {
		$attempt = isset( $args['attempt'] ) ? (int) $args['attempt'] : 1;

		if ( isset( $args['payload'] ) && is_array( $args['payload'] ) ) {
			$payload = $args['payload'];
		} else {
			$post = get_post( $post_id );
			if ( ! $post || ! $this->is_syncable( $post ) ) {
				return;
			}
			if ( 'upsert' === $action && 'publish' !== $post->post_status ) {
				return;
			}
			$payload = $this->build_payload( $post, $action );
		}

		$body      = wp_json_encode( $payload );
		$timestamp = time();
		$secret    = Analyse_Settings::get( 'signing_secret' );

		$response = wp_remote_post(
			Analyse_Settings::get( 'ingest_url' ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'User-Agent'           => 'Analyse-WordPress/' . ANALYSE_PLUGIN_VERSION,
					'X-Analyse-Public-Key' => Analyse_Settings::get( 'public_key' ),
					'X-Analyse-Timestamp'  => (string) $timestamp,
					'X-Analyse-Signature'  => Analyse_Signature::sign_sync( $body, $secret, $timestamp ),
				),
				'body'    => $body,
			)
		);

		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			if ( $post_id ) {
				update_post_meta(
					$post_id,
					'_analyse_sync_status',
					array(
						'status' => 'ok',
						'action' => $action,
						'time'   => $timestamp,
					)
				);
			}

			return;
		}

		// 4xx (other than 408/429) means a permanent rejection — retrying won't help.
		$permanent = $code >= 400 && $code < 500 && 408 !== $code && 429 !== $code;

		if ( $post_id ) {
			update_post_meta(
				$post_id,
				'_analyse_sync_status',
				array(
					'status'  => $permanent || $attempt >= self::MAX_ATTEMPTS ? 'failed' : 'retrying',
					'action'  => $action,
					'time'    => $timestamp,
					'code'    => $code,
					'attempt' => $attempt,
				)
			);
		}

		if ( $permanent || $attempt >= self::MAX_ATTEMPTS ) {
			return;
		}

		$delay = self::$retry_delays[ min( $attempt - 1, count( self::$retry_delays ) - 1 ) ];
		$next  = array( 'attempt' => $attempt + 1 );
		if ( isset( $args['payload'] ) ) {
			$next['payload'] = $args['payload'];
		}
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $post_id, $action, $next ) );
	}

	/**
	 * Builds the ingest payload for a post.
	 *
	 * @param WP_Post $post   Post object.
	 * @param string  $action "upsert" or "delete".
	 * @return array
	 */
	private function build_payload( $post, $action ) {
		$content_html = '';
		if ( 'upsert' === $action ) {
			$content_html = apply_filters( 'the_content', $post->post_content );
		}

		$categories = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
		$tags       = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		$image_url  = get_the_post_thumbnail_url( $post, 'full' );
		$author     = get_the_author_meta( 'display_name', $post->post_author );

		return array(
			'source'  => 'wordpress-plugin',
			'version' => ANALYSE_PLUGIN_VERSION,
			'action'  => $action,
			'post'    => array(
				'guid'               => get_the_guid( $post ),
				'wp_post_id'         => $post->ID,
				'slug'               => $post->post_name,
				'title'              => get_the_title( $post ),
				'status'             => $post->post_status,
				'excerpt'            => $post->post_excerpt ? $post->post_excerpt : null,
				'content_html'       => $content_html,
				'featured_image_url' => $image_url ? $image_url : null,
				'categories'         => is_wp_error( $categories ) ? array() : array_values( $categories ),
				'tags'               => is_wp_error( $tags ) ? array() : array_values( $tags ),
				'author_name'        => $author ? $author : null,
				'permalink'          => get_permalink( $post ),
				'published_at'       => mysql2date( 'c', $post->post_date_gmt, false ),
				'modified_at'        => mysql2date( 'c', $post->post_modified_gmt, false ),
			),
		);
	}
}
