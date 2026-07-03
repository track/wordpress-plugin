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
 * Injects the Analyse SDK script tag into wp_head.
 */
class Analyse_Snippet {

	const SDK_SRC = 'https://unpkg.com/@analyse.net/sdk@0/dist/index.global.js';

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
	 * Hooks the snippet output.
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'output_snippet' ), 20 );
	}

	/**
	 * Prints the SDK script tag when tracking applies to this request.
	 */
	public function output_snippet() {
		if ( ! $this->should_track() ) {
			return;
		}

		$public_key = Analyse_Settings::get( 'public_key' );
		$pulse_host = Analyse_Settings::get( 'pulse_host' );

		/**
		 * Filters the SDK script URL, e.g. to self-host the tracker.
		 *
		 * @param string $src Script URL.
		 */
		$src = apply_filters( 'analyse_snippet_src', self::SDK_SRC );

		$attributes = ' data-public-key="' . esc_attr( $public_key ) . '"';
		if ( $pulse_host && Analyse_Settings::DEFAULT_PULSE_HOST !== $pulse_host ) {
			$attributes .= ' data-host="' . esc_attr( $pulse_host ) . '"';
		}

		// The SDK auto-initializes from the script tag's data attributes.
		echo '<script defer src="' . esc_url( $src ) . '"' . $attributes . '></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
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
