<?php
/**
 * Cleans up plugin data on uninstall.
 *
 * @package Analyse
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'analyse_settings' );

// Post meta (_analyse_post_id, _analyse_sync_status) is intentionally kept so
// re-installing the plugin keeps existing Analyse-published posts linked.
