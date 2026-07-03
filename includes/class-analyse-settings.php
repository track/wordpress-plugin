<?php
/**
 * Settings → Analyse admin page and option storage.
 *
 * @package Analyse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings page and provides typed access to plugin options.
 */
class Analyse_Settings {

	const OPTION_KEY = 'analyse_settings';

	const DEFAULT_PULSE_HOST = 'https://pulse.analyse.net';
	const DEFAULT_INGEST_URL = 'https://analyse.net/api/ingest/wordpress';

	/**
	 * Singleton instance.
	 *
	 * @var Analyse_Settings|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return Analyse_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the settings page into WP admin.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_analyse_test_event', array( $this, 'handle_test_event' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Returns option defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'public_key'              => '',
			'pulse_host'              => self::DEFAULT_PULSE_HOST,
			'signing_secret'          => '',
			'tracking_enabled'        => true,
			'track_logged_in'         => false,
			'respect_dnt'             => true,
			'ingest_enabled'          => false,
			'ingest_url'              => self::DEFAULT_INGEST_URL,
			'publish_receive_enabled' => true,
			'default_post_status'     => 'publish',
			'default_author_id'       => 0,
		);
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Returns a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Registers the option and sanitizer.
	 */
	public function register_settings() {
		register_setting(
			'analyse_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitizes submitted settings.
	 *
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$pulse_host = isset( $input['pulse_host'] ) ? esc_url_raw( trim( (string) $input['pulse_host'] ) ) : '';
		$ingest_url = isset( $input['ingest_url'] ) ? esc_url_raw( trim( (string) $input['ingest_url'] ) ) : '';

		return array(
			'public_key'              => isset( $input['public_key'] ) ? sanitize_text_field( $input['public_key'] ) : '',
			'pulse_host'              => $pulse_host ? untrailingslashit( $pulse_host ) : $defaults['pulse_host'],
			'signing_secret'          => isset( $input['signing_secret'] ) ? sanitize_text_field( $input['signing_secret'] ) : '',
			'tracking_enabled'        => ! empty( $input['tracking_enabled'] ),
			'track_logged_in'         => ! empty( $input['track_logged_in'] ),
			'respect_dnt'             => ! empty( $input['respect_dnt'] ),
			'ingest_enabled'          => ! empty( $input['ingest_enabled'] ),
			'ingest_url'              => $ingest_url ? untrailingslashit( $ingest_url ) : $defaults['ingest_url'],
			'publish_receive_enabled' => ! empty( $input['publish_receive_enabled'] ),
			'default_post_status'     => ( isset( $input['default_post_status'] ) && 'draft' === $input['default_post_status'] ) ? 'draft' : 'publish',
			'default_author_id'       => isset( $input['default_author_id'] ) ? absint( $input['default_author_id'] ) : 0,
		);
	}

	/**
	 * Adds the page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Analyse', 'analyse' ),
			__( 'Analyse', 'analyse' ),
			'manage_options',
			'analyse',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Sends a single server-side test event to the configured pulse host.
	 */
	public function handle_test_event() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'analyse' ) );
		}
		check_admin_referer( 'analyse_test_event' );

		$public_key = self::get( 'public_key' );
		$result     = 'error';

		if ( $public_key ) {
			$response = wp_remote_post(
				self::get( 'pulse_host' ) . '/v1/event',
				array(
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'publicKey' => $public_key,
							'event'     => array(
								'event_name'   => 'analyse_plugin_test',
								'anonymous_id' => 'wp-plugin-' . wp_generate_uuid4(),
								'url'          => home_url( '/' ),
								'hostname'     => wp_parse_url( home_url(), PHP_URL_HOST ),
								'properties'   => array( 'source' => 'wordpress-plugin' ),
							),
						)
					),
				)
			);

			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$result = 'ok';
			} elseif ( 401 === $code || 403 === $code ) {
				$result = 'unauthorized';
			}
		} else {
			$result = 'missing_key';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'analyse',
					'analyse_test' => $result,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Shows the test-event result notice.
	 */
	public function render_notices() {
		if ( ! isset( $_GET['analyse_test'] ) || ! isset( $_GET['page'] ) || 'analyse' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['analyse_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $result ) {
			case 'ok':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test event sent — check your Analyse dashboard.', 'analyse' ) . '</p></div>';
				break;
			case 'unauthorized':
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Analyse rejected the public key. Copy it from your site settings in Analyse.', 'analyse' ) . '</p></div>';
				break;
			case 'missing_key':
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Add your public key first, then send a test event.', 'analyse' ) . '</p></div>';
				break;
			default:
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not reach Analyse. Check the host setting and try again.', 'analyse' ) . '</p></div>';
		}
	}

	/**
	 * Renders the settings form.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = self::all();
		$webhook_url = rest_url( 'analyse/v1/publish' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Analyse', 'analyse' ); ?></h1>

			<h2><?php esc_html_e( 'Connection', 'analyse' ); ?></h2>
			<p>
				<?php esc_html_e( 'Webhook URL for publishing from Analyse to this site — paste this in the Analyse WordPress integration:', 'analyse' ); ?>
				<br />
				<code id="analyse-webhook-url"><?php echo esc_html( $webhook_url ); ?></code>
				<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('analyse-webhook-url').textContent)">
					<?php esc_html_e( 'Copy', 'analyse' ); ?>
				</button>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'analyse_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="analyse-public-key"><?php esc_html_e( 'Public key', 'analyse' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="analyse-public-key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[public_key]" value="<?php echo esc_attr( $settings['public_key'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Your site public key from Analyse (Site settings → Tracking).', 'analyse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="analyse-signing-secret"><?php esc_html_e( 'Signing secret', 'analyse' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="analyse-signing-secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[signing_secret]" value="<?php echo esc_attr( $settings['signing_secret'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'The same secret you generated in the Analyse WordPress integration. It verifies publishes from Analyse and signs posts synced to Analyse.', 'analyse' ); ?></p>
						</td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Analytics tracking', 'analyse' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'analyse' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tracking_enabled]" value="1" <?php checked( $settings['tracking_enabled'] ); ?> /> <?php esc_html_e( 'Add the Analyse analytics snippet to the site front-end', 'analyse' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Track logged-in users', 'analyse' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[track_logged_in]" value="1" <?php checked( $settings['track_logged_in'] ); ?> /> <?php esc_html_e( 'Also track visits from logged-in users (off by default to exclude editors)', 'analyse' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Respect Do Not Track', 'analyse' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[respect_dnt]" value="1" <?php checked( $settings['respect_dnt'] ); ?> /> <?php esc_html_e( 'Skip the snippet for visitors sending DNT or Global Privacy Control headers', 'analyse' ); ?></label>
						</td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Sync posts to Analyse', 'analyse' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable sync', 'analyse' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ingest_enabled]" value="1" <?php checked( $settings['ingest_enabled'] ); ?> /> <?php esc_html_e( 'Send published WordPress posts to Analyse for content analytics', 'analyse' ); ?></label>
							<p class="description"><?php esc_html_e( 'Requires the public key and signing secret above.', 'analyse' ); ?></p>
						</td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Publishing from Analyse', 'analyse' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Accept publishes', 'analyse' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[publish_receive_enabled]" value="1" <?php checked( $settings['publish_receive_enabled'] ); ?> /> <?php esc_html_e( 'Create WordPress posts when Analyse publishes to this site', 'analyse' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="analyse-post-status"><?php esc_html_e( 'New post status', 'analyse' ); ?></label></th>
						<td>
							<select id="analyse-post-status" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_post_status]">
								<option value="publish" <?php selected( $settings['default_post_status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'analyse' ); ?></option>
								<option value="draft" <?php selected( $settings['default_post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'analyse' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="analyse-author"><?php esc_html_e( 'Post author', 'analyse' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'id'               => 'analyse-author',
									'name'             => self::OPTION_KEY . '[default_author_id]',
									'selected'         => (int) $settings['default_author_id'],
									'show_option_none' => __( 'Site default', 'analyse' ),
									'capability'       => 'edit_posts',
								)
							);
							?>
						</td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Advanced', 'analyse' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><label for="analyse-pulse-host"><?php esc_html_e( 'Analytics host', 'analyse' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="analyse-pulse-host" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pulse_host]" value="<?php echo esc_attr( $settings['pulse_host'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave as-is unless Analyse support tells you otherwise.', 'analyse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="analyse-ingest-url"><?php esc_html_e( 'Sync endpoint', 'analyse' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="analyse-ingest-url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ingest_url]" value="<?php echo esc_attr( $settings['ingest_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave as-is unless Analyse support tells you otherwise.', 'analyse' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="analyse_test_event" />
				<?php wp_nonce_field( 'analyse_test_event' ); ?>
				<?php submit_button( __( 'Send test event', 'analyse' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
