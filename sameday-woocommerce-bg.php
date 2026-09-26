<?php
/**
 * Plugin Name: Sameday WooCommerce България
 * Plugin URI: https://example.com/sameday-woocommerce-bg
 * Description: Добавя методи за доставка със Sameday - EasyBox и Куриер 24 часа, Speedy - офис, адрес и АПС, и A1POST за международни доставки. Включва пълна Speedy API интеграция (профил, договор, генериране, принтиране и изтриване на товарителници), безплатна доставка при плащане с карта и ограничаване на куриерите по държава.
 * Version: 1.7.2
 * Author: PADev
 * Author URI:
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: sameday-woocommerce-bg
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Prevent duplicate loading when an old copy of the plugin is still present.
if ( defined( 'SAMEDAY_WOOCOMMERCE_BG_BOOTSTRAPPED' ) ) {
	return;
}

define( 'SAMEDAY_WOOCOMMERCE_BG_BOOTSTRAPPED', true );

/**
 * Current plugin version.
 */
if ( ! defined( 'SAMEDAY_WOOCOMMERCE_BG_VERSION' ) ) {
	define( 'SAMEDAY_WOOCOMMERCE_BG_VERSION', '1.7.2' );
}

/**
 * Plugin directory path.
 */
if ( ! defined( 'SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR' ) ) {
	define( 'SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Plugin directory URL.
 */
if ( ! defined( 'SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL' ) ) {
	define( 'SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'SAMEDAY_WOOCOMMERCE_BG_PLUGIN_BASENAME' ) ) {
	define( 'SAMEDAY_WOOCOMMERCE_BG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * The code that runs during plugin activation.
 */
if ( ! function_exists( 'activate_sameday_woocommerce_bg' ) ) {
	function activate_sameday_woocommerce_bg() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-sameday-woocommerce-bg-activator.php';
		Sameday_Woocommerce_Bg_Activator::activate();
	}
}

/**
 * The code that runs during plugin deactivation.
 */
if ( ! function_exists( 'deactivate_sameday_woocommerce_bg' ) ) {
	function deactivate_sameday_woocommerce_bg() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-sameday-woocommerce-bg-deactivator.php';
		Sameday_Woocommerce_Bg_Deactivator::deactivate();
	}
}

register_activation_hook( __FILE__, 'activate_sameday_woocommerce_bg' );
register_deactivation_hook( __FILE__, 'deactivate_sameday_woocommerce_bg' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
if ( ! class_exists( 'Sameday_Woocommerce_Bg', false ) ) {
	require plugin_dir_path( __FILE__ ) . 'includes/class-sameday-woocommerce-bg.php';
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
if ( ! function_exists( 'run_sameday_woocommerce_bg' ) ) {
	function run_sameday_woocommerce_bg() {

		$plugin = new Sameday_Woocommerce_Bg();
		$plugin->run();

	}
}

if ( ! has_action( 'plugins_loaded', 'run_sameday_woocommerce_bg' ) ) {
	add_action( 'plugins_loaded', 'run_sameday_woocommerce_bg', 20 );
}
