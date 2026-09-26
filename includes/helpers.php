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
		'enable_a1post_provider'   => 'yes',
		'free_shipping_threshold_sameday' => '',
		'free_shipping_threshold_speedy'  => '',
		'free_shipping_threshold_a1post'  => '',
		'card_free_shipping_enabled'   => 'yes',
		'card_free_shipping_threshold' => '49.99',
		'card_free_shipping_scope'     => 'all',
		'card_free_shipping_gateways'  => array(),
		'card_free_shipping_payment_scope' => 'all',
		'free_shipping_country_scope'      => 'domestic',
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

	if ( 'a1post' === $provider ) {
		return 'yes' === sameday_get_setting( 'enable_a1post_provider', 'yes' );
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
	} elseif ( 'a1post' === $provider ) {
		$threshold = sameday_get_setting( 'free_shipping_threshold_a1post', '' );
	} else {
		return 0.0;
	}

	if ( '' === $threshold || null === $threshold ) {
		return 0.0;
	}

	return max( 0.0, (float) wc_format_decimal( $threshold ) );
}

/**
 * Country scope of one provider.
 *
 * "domestic"      - offered only for Bulgarian addresses.
 * "international" - offered only for addresses outside Bulgaria.
 *
 * @param string $provider Provider code.
 * @return string
 */
function sameday_get_provider_country_scope( $provider ) {
	$scope = 'a1post' === $provider ? 'international' : 'domestic';

	return (string) apply_filters( 'sameday_provider_country_scope', $scope, $provider );
}

/**
 * Store base country, used when the customer has not picked one yet.
 *
 * @return string
 */
function sameday_get_default_country() {
	if ( function_exists( 'wc_get_base_location' ) ) {
		$base = wc_get_base_location();

		if ( ! empty( $base['country'] ) ) {
			return strtoupper( (string) $base['country'] );
		}
	}

	return 'BG';
}

/**
 * Whether a provider may be offered for the given destination country.
 *
 * @param string $provider Provider code.
 * @param string $country  ISO-2 country code.
 * @return bool
 */
function sameday_provider_supports_country( $provider, $country ) {
	$country = strtoupper( trim( (string) $country ) );

	if ( '' === $country ) {
		$country = sameday_get_default_country();
	}

	$supported = 'international' === sameday_get_provider_country_scope( $provider )
		? 'BG' !== $country
		: 'BG' === $country;

	return (bool) apply_filters( 'sameday_provider_supports_country', $supported, $provider, $country );
}

/**
 * Resolve the destination country of the current customer.
 *
 * @return string
 */
function sameday_get_customer_country() {
	$country = '';

	if ( isset( $_POST['ship_to_different_address'], $_POST['shipping_country'] ) && ! empty( $_POST['ship_to_different_address'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$country = wc_clean( wp_unslash( $_POST['shipping_country'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	} elseif ( isset( $_POST['billing_country'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$country = wc_clean( wp_unslash( $_POST['billing_country'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	if ( '' === $country && function_exists( 'WC' ) && WC()->customer ) {
		$country = (string) WC()->customer->get_shipping_country();

		if ( '' === $country ) {
			$country = (string) WC()->customer->get_billing_country();
		}
	}

	if ( '' === $country ) {
		$country = sameday_get_default_country();
	}

	return strtoupper( $country );
}

/**
 * Whether the "free shipping when paying by card" rule is switched on.
 *
 * @return bool
 */
function sameday_is_card_free_shipping_enabled() {
	return 'yes' === sameday_get_setting( 'card_free_shipping_enabled', 'yes' );
}

/**
 * Order value from which card payments get free shipping.
 *
 * @return float
 */
function sameday_get_card_free_shipping_threshold() {
	$threshold = sameday_get_setting( 'card_free_shipping_threshold', '49.99' );

	if ( '' === $threshold || null === $threshold ) {
		return 0.0;
	}

	return max( 0.0, (float) wc_format_decimal( $threshold ) );
}

/**
 * Which services the card rule covers: "all" or "pickup" (office / locker only).
 *
 * @return string
 */
function sameday_get_card_free_shipping_scope() {
	return 'pickup' === sameday_get_setting( 'card_free_shipping_scope', 'all' ) ? 'pickup' : 'all';
}

/**
 * Which payment methods the order value free shipping rule covers.
 *
 * "card" - only the gateways that count as a card payment (default).
 * "all"  - every gateway, including cash on delivery.
 *
 * @return string
 */
function sameday_get_free_shipping_payment_scope() {
	return 'card' === sameday_get_setting( 'card_free_shipping_payment_scope', 'all' ) ? 'card' : 'all';
}

/**
 * Which destinations the free shipping rules cover.
 *
 * "domestic" - only addresses in the countries returned by
 *              sameday_get_free_shipping_countries() (default: Bulgaria).
 * "all"      - every destination the shop delivers to.
 *
 * @return string
 */
function sameday_get_free_shipping_country_scope() {
	return 'all' === sameday_get_setting( 'free_shipping_country_scope', 'domestic' ) ? 'all' : 'domestic';
}

/**
 * Countries that may receive free shipping while the scope is "domestic".
 *
 * @return array<int, string>
 */
function sameday_get_free_shipping_countries() {
	$countries = (array) apply_filters( 'sameday_free_shipping_countries', array( sameday_get_default_country() ) );
	$countries = array_map( 'strtoupper', array_map( 'strval', $countries ) );

	return array_values( array_unique( array_filter( $countries ) ) );
}

/**
 * Whether one destination country may receive free shipping.
 *
 * @param string $country ISO-2 country code.
 * @return bool
 */
function sameday_is_free_shipping_country( $country ) {
	$country = strtoupper( trim( (string) $country ) );

	if ( '' === $country ) {
		$country = sameday_get_default_country();
	}

	$eligible = 'all' === sameday_get_free_shipping_country_scope()
		|| in_array( $country, sameday_get_free_shipping_countries(), true );

	return (bool) apply_filters( 'sameday_is_free_shipping_country', $eligible, $country );
}

/**
 * Payment gateway id fragments that are never treated as a card payment.
 *
 * Used only when the shop owner has not ticked the gateways explicitly.
 *
 * @return array<int, string>
 */
function sameday_get_non_card_gateway_patterns() {
	return (array) apply_filters(
		'sameday_non_card_gateway_patterns',
		array( 'cod', 'cheque', 'bacs', 'nalozh', 'naloji', 'nalojen', 'cash', 'bank', 'invoice', 'local_pickup' )
	);
}

/**
 * Whether one payment gateway id counts as a card payment.
 *
 * @param string $payment_method Gateway id.
 * @return bool
 */
function sameday_is_card_payment_method( $payment_method ) {
	$payment_method = strtolower( trim( (string) $payment_method ) );

	if ( '' === $payment_method ) {
		return false;
	}

	$selected = sameday_get_setting( 'card_free_shipping_gateways', array() );

	if ( is_array( $selected ) && ! empty( $selected ) ) {
		$selected = array_map( 'strtolower', array_map( 'strval', $selected ) );

		return (bool) apply_filters( 'sameday_is_card_payment_method', in_array( $payment_method, $selected, true ), $payment_method );
	}

	$is_card = true;

	foreach ( sameday_get_non_card_gateway_patterns() as $pattern ) {
		if ( '' !== $pattern && false !== strpos( $payment_method, strtolower( $pattern ) ) ) {
			$is_card = false;
			break;
		}
	}

	return (bool) apply_filters( 'sameday_is_card_payment_method', $is_card, $payment_method );
}

/**
 * Return the gateway ids that currently count as card payments.
 *
 * @return array<int, string>
 */
function sameday_get_card_gateway_ids() {
	$ids = array();

	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return $ids;
	}

	foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway_id => $gateway ) {
		if ( sameday_is_card_payment_method( $gateway_id ) ) {
			$ids[] = (string) $gateway_id;
		}
	}

	return $ids;
}

/**
 * Whether a remote catalogue sync is on cool-down after a failure.
 *
 * Without this a shop whose host cannot reach the carrier retries the same
 * failing request on every single checkout page load, which is what turns a
 * broken lookup into a checkout that hangs until PHP times out.
 *
 * @param string $key Sync key.
 * @return bool
 */
function sameday_sync_is_backed_off( $key ) {
	return (bool) get_transient( 'sameday_sync_backoff_' . $key );
}

/**
 * Put one remote catalogue sync on cool-down.
 *
 * @param string $key     Sync key.
 * @param int    $seconds Cool-down length.
 * @return void
 */
function sameday_sync_start_backoff( $key, $seconds = 900 ) {
	set_transient( 'sameday_sync_backoff_' . $key, time(), max( 60, (int) $seconds ) );
}

/**
 * Clear the cool-down after a successful sync.
 *
 * @param string $key Sync key.
 * @return void
 */
function sameday_sync_clear_backoff( $key ) {
	delete_transient( 'sameday_sync_backoff_' . $key );
}

/**
 * Whether the current request may spend time on a remote catalogue sync.
 *
 * Admin screens, WP-CLI and cron may; a customer waiting on the checkout
 * lookup may not - they get whatever is cached and a background refresh.
 *
 * @return bool
 */
function sameday_can_sync_in_request() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true;
	}

	return is_admin() && ! wp_doing_ajax();
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
