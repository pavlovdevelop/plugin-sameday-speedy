<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 * @author     Sameday WooCommerce BG <contact@example.com>
 */
class Sameday_Woocommerce_Bg_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-speedy-rate-cache.php';
		Speedy_Rate_Cache::schedule_cron();
	}

}