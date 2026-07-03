<?php
/**
 * Front-end analytics snippet injection.
 *
 * @package Analyse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the bundled Analyse SDK on the site front-end.
 */
class Analyse_Snippet {

	const HANDLE = 'analyse-sdk';

	/**
	 * Singleton instance.
	 *
	 * @var Analyse_Snippet|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return Analyse_Snippet
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the script enqueue and tag filter.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_snippet' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_data_attributes' ), 10, 2 );
	}

	/**
	 * Enqueues the SDK when tracking applies to this request.
	 */
	public function enqueue_snippet() {
		if ( ! $this->should_track() ) {
			return;
		}

		/**
		 * Filters the SDK script URL, e.g. to serve a customized build.
		 *
		 * @param string $src Script URL. Defaults to the copy bundled with the plugin.
		 */
		$src = apply_filters( 'analyse_snippet_src', plugins_url( 'assets/analyse.js', ANALYSE_PLUGIN_FILE ) );

		wp_enqueue_script( self::HANDLE, $src, array(), ANALYSE_PLUGIN_VERSION, false );
	}

	/**
	 * Adds the auto-init data attributes and defer to the SDK script tag.
	 *
	 * The SDK reads `data-public-key` / `data-host` from its own script tag,
	 * so no inline configuration script is needed.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function add_data_attributes( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		$attributes = ' defer data-public-key="' . esc_attr( Analyse_Settings::get( 'public_key' ) ) . '"';

		$pulse_host = Analyse_Settings::get( 'pulse_host' );
		if ( $pulse_host && Analyse_Settings::DEFAULT_PULSE_HOST !== $pulse_host ) {
			$attributes .= ' data-host="' . esc_attr( $pulse_host ) . '"';
		}

		return str_replace( ' src=', $attributes . ' src=', $tag );
	}

	/**
	 * Whether the current request should receive the tracking snippet.
	 *
	 * @return bool
	 */
	private function should_track() {
		if ( ! Analyse_Settings::get( 'tracking_enabled' ) || ! Analyse_Settings::get( 'public_key' ) ) {
			return false;
		}

		if ( is_admin() || is_preview() || is_customize_preview() ) {
			return false;
		}

		if ( is_user_logged_in() && ! Analyse_Settings::get( 'track_logged_in' ) ) {
			return false;
		}

		if ( Analyse_Settings::get( 'respect_dnt' ) ) {
			$dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
			$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';
			if ( '1' === $dnt || '1' === $gpc ) {
				return false;
			}
		}

		return true;
	}
}
