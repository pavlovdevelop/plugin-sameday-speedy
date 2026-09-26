<?php
/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 * @author     Sameday WooCommerce BG <contact@example.com>
 */
class Sameday_Woocommerce_Bg_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'sameday-woocommerce-bg',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}