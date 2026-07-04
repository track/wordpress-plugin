<?php
/**
 * Plugin Name:       Analyse
 * Plugin URI:        https://analyse.net/integrations/wordpress
 * Description:       Connect your WordPress site to Analyse — analytics event tracking, blog post sync to Analyse, and auto-publishing of Analyse posts to WordPress.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Analyse
 * Author URI:        https://analyse.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       analyse
 * Domain Path:       /languages
 *
 * @package Analyse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANALYSE_PLUGIN_VERSION', '1.0.0' );
define( 'ANALYSE_PLUGIN_FILE', __FILE__ );
define( 'ANALYSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ANALYSE_PLUGIN_DIR . 'includes/class-analyse-settings.php';
require_once ANALYSE_PLUGIN_DIR . 'includes/class-analyse-signature.php';
require_once ANALYSE_PLUGIN_DIR . 'includes/class-analyse-snippet.php';
require_once ANALYSE_PLUGIN_DIR . 'includes/class-analyse-sync.php';
require_once ANALYSE_PLUGIN_DIR . 'includes/class-analyse-rest.php';

/**
 * Loads the plugin text domain for translations.
 */
function analyse_load_textdomain() {
	load_plugin_textdomain( 'analyse', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'analyse_load_textdomain' );

Analyse_Settings::instance()->register();
Analyse_Snippet::instance()->register();
Analyse_Sync::instance()->register();
Analyse_Rest::instance()->register();
