<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 * @author     Sameday WooCommerce BG <contact@example.com>
 */
class Sameday_Woocommerce_Bg_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-speedy-rate-cache.php';
		Speedy_Rate_Cache::clear_cron();
	}

}