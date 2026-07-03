<?php
/**
 * HMAC signing helpers shared by the REST receiver and the sync sender.
 *
 * @package Analyse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC-SHA256 helpers matching the Analyse webhook signature scheme.
 */
class Analyse_Signature {

	/**
	 * Verifies an inbound `X-Analyse-Signature: sha256=<hex>` header.
	 *
	 * Analyse signs the raw JSON request body with HMAC-SHA256.
	 *
	 * @param string $raw_body Raw request body.
	 * @param string $header   Signature header value.
	 * @param string $secret   Shared signing secret.
	 * @return bool
	 */
	public static function verify( $raw_body, $header, $secret ) {
		if ( ! is_string( $header ) || '' === $secret ) {
			return false;
		}

		if ( 0 !== strpos( $header, 'sha256=' ) ) {
			return false;
		}

		$provided = substr( $header, strlen( 'sha256=' ) );
		$expected = hash_hmac( 'sha256', $raw_body, $secret );

		return hash_equals( $expected, strtolower( $provided ) );
	}

	/**
	 * Signs an outbound sync body as `sha256=<hmac(timestamp . "." . body)>`.
	 *
	 * The timestamp is bound into the signature so replays outside the server's
	 * acceptance window are rejected.
	 *
	 * @param string $body      Raw JSON body.
	 * @param string $secret    Shared signing secret.
	 * @param int    $timestamp Unix timestamp (seconds).
	 * @return string
	 */
	public static function sign_sync( $body, $secret, $timestamp ) {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}
}
