<?php
/**
 * Helper functions for Sameday WooCommerce BG plugin
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if WooCommerce is active
 *
 * @return bool
 */
function sameday_wc_is_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Get plugin settings
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function sameday_get_setting( $key, $default = false ) {
	$settings = sameday_get_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

/**
 * Get all plugin settings merged with defaults.
 *
 * @return array
 */
function sameday_get_settings() {
	$defaults = array(
		'enabled'                  => 'yes',
		'replace_wc_shipping'      => 'yes',
		'enable_sameday_provider'  => 'yes',
		'enable_speedy_provider'   => 'yes',
		'free_shipping_threshold_sameday' => '',
		'free_shipping_threshold_speedy'  => '',
	);

	$settings = get_option( 'sameday_woocommerce_bg_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args( $settings, $defaults );
}

/**
 * Whether the plugin delivery flow is enabled.
 *
 * @return bool
 */
function sameday_is_plugin_enabled() {
	return 'yes' === sameday_get_setting( 'enabled', 'yes' );
}

/**
 * Whether plugin replaces WooCommerce shipping methods.
 *
 * @return bool
 */
function sameday_replaces_wc_shipping() {
	return 'yes' === sameday_get_setting( 'replace_wc_shipping', 'yes' );
}

/**
 * Whether a provider is enabled in plugin settings.
 *
 * @param string $provider Provider code.
 * @return bool
 */
function sameday_is_provider_enabled( $provider ) {
	if ( 'sameday' === $provider ) {
		return 'yes' === sameday_get_setting( 'enable_sameday_provider', 'yes' );
	}

	if ( 'speedy' === $provider ) {
		return 'yes' === sameday_get_setting( 'enable_speedy_provider', 'yes' );
	}

	return false;
}

/**
 * Return the configured free shipping threshold for one provider.
 *
 * @param string $provider Provider code.
 * @return float
 */
function sameday_get_free_shipping_threshold( $provider ) {
	if ( 'sameday' === $provider ) {
		$threshold = sameday_get_setting( 'free_shipping_threshold_sameday', '' );
	} elseif ( 'speedy' === $provider ) {
		$threshold = sameday_get_setting( 'free_shipping_threshold_speedy', '' );
	} else {
		return 0.0;
	}

	if ( '' === $threshold || null === $threshold ) {
		return 0.0;
	}

	return max( 0.0, (float) wc_format_decimal( $threshold ) );
}

/**
 * Sanitize text for database storage
 *
 * @param string $text
 * @return string
 */
function sameday_sanitize_text( $text ) {
	return sanitize_text_field( wp_unslash( $text ) );
}

/**
 * Get EasyBox locations
 *
 * @return array
 */
function sameday_get_locations() {
	$location_repo = new Sameday_Location_Repository();
	return $location_repo->get_all_locations();
}

/**
 * Get EasyBox cities
 *
 * @return array
 */
function sameday_get_cities() {
	$location_repo = new Sameday_Location_Repository();
	$cities        = array();

	foreach ( $location_repo->get_all_cities() as $city ) {
		if ( ! empty( $city['name'] ) ) {
			$cities[] = $city['name'];
		}
	}

	return $cities;
}

/**
 * Get EasyBox sync metadata.
 *
 * @return array
 */
function sameday_get_location_sync_meta() {
	$location_repo = new Sameday_Location_Repository();
	return $location_repo->get_sync_meta();
}

/**
 * Get Speedy sync metadata.
 *
 * @return array
 */
function sameday_get_speedy_location_sync_meta() {
	$location_repo = new Speedy_Location_Repository();
	return $location_repo->get_sync_meta();
}

/**
 * Calculate EasyBox price
 *
 * @param float $weight
 * @param float $base_cost
 * @param float $additional_kg_cost
 * @return float
 */
function sameday_calculate_easybox_price( $weight, $base_cost, $additional_kg_cost ) {
	$calculator = new Sameday_Price_Calculator();
	return $calculator->calculate_easybox_price( $weight, $base_cost, $additional_kg_cost );
}

/**
 * Calculate Courier price
 *
 * @param float $weight
 * @param float $base_cost
 * @param float $additional_kg_cost
 * @return float
 */
function sameday_calculate_courier_price( $weight, $base_cost, $additional_kg_cost ) {
	$calculator = new Sameday_Price_Calculator();
	return $calculator->calculate_courier_price( $weight, $base_cost, $additional_kg_cost );
}

/**
 * Calculate COD fee
 *
 * @param float $order_total
 * @param float $cod_percent
 * @param float $minimum_fee
 * @return float
 */
function sameday_calculate_cod_fee( $order_total, $cod_percent = 1, $minimum_fee = 0.50 ) {
	$calculator = new Sameday_Price_Calculator();
	return $calculator->calculate_cod_fee( $order_total, $cod_percent, $minimum_fee );
}
