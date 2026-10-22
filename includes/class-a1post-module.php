<?php
/**
 * Bridge to the official "a1post Shipping" plugin (A1POST_Shipping_Method).
 *
 * When that plugin is active, international delivery uses its settings
 * (credentials, countries, services, surcharge, free shipping) and its live
 * rates from api.a1post.bg instead of the built-in zone tariff.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A1POST module bridge.
 */
class A1post_Module {

	/**
	 * Transient prefix for cached rates.
	 */
	const CACHE_PREFIX = 'sameday_a1m_rates_';

	/**
	 * Rate cache lifetime in seconds.
	 */
	const CACHE_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Shipping method instance.
	 *
	 * @var WC_Shipping_Method|null
	 */
	private static $method = null;

	/**
	 * Per-request rate cache.
	 *
	 * @var array<string, array>
	 */
	private static $memory = array();

	/**
	 * Whether the official A1POST plugin is installed and active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( ! class_exists( 'A1POST_Shipping_Method', false ) && function_exists( 'a1post_shipping_method' ) && class_exists( 'WC_Shipping_Method' ) ) {
			// The plugin declares its class lazily on "woocommerce_shipping_init".
			a1post_shipping_method();
		}

		return class_exists( 'A1POST_Shipping_Method', false );
	}

	/**
	 * Whether the module is active and enabled in its own settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$method = self::get_method();

		return $method && 'yes' === self::setting( 'enabled' );
	}

	/**
	 * Whether the module ships to a country (its "countries" minus "escape_countries").
	 *
	 * @param string $country ISO-2 country code.
	 * @return bool
	 */
	public static function supports_country( $country ) {
		$country = strtoupper( (string) $country );

		if ( '' === $country || ! self::is_enabled() ) {
			return false;
		}

		$countries = self::setting( 'countries' );
		$escape    = self::setting( 'escape_countries' );

		return is_array( $countries ) && in_array( $country, $countries, true )
			&& ! ( is_array( $escape ) && in_array( $country, $escape, true ) );
	}

	/**
	 * Live A1POST rates for the current cart.
	 *
	 * @param string $country  Destination ISO-2 code.
	 * @param string $postcode Destination post code.
	 * @return array<int, array{id: string, code: string, label: string, cost: float}> Sorted by cost.
	 */
	public static function get_rates( $country, $postcode = '' ) {
		$country  = strtoupper( (string) $country );
		$postcode = trim( (string) $postcode );

		if ( ! self::supports_country( $country ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$key = md5( wp_json_encode( array( $country, $postcode, WC()->cart->get_cart_hash(), get_woocommerce_currency(), self::get_method()->settings, class_exists( 'A1post_Tariff' ) ? A1post_Tariff::get_settings() : array() ) ) );

		if ( isset( self::$memory[ $key ] ) ) {
			return self::$memory[ $key ];
		}

		$cached = get_transient( self::CACHE_PREFIX . $key );

		if ( is_array( $cached ) ) {
			self::$memory[ $key ] = $cached;

			return $cached;
		}

		$rates = self::calculate( $country, $postcode );

		self::$memory[ $key ] = $rates;

		// Only cache real answers, so an A1POST outage is retried on the next refresh.
		if ( ! empty( $rates ) ) {
			set_transient( self::CACHE_PREFIX . $key, $rates, self::CACHE_TTL );
		}

		return $rates;
	}

	/**
	 * One rate by ID, or the cheapest one when the ID is empty or unknown.
	 *
	 * @param string $country  Destination ISO-2 code.
	 * @param string $postcode Destination post code.
	 * @param string $rate_id  Rate ID, e.g. "a1post_L".
	 * @return array{id: string, code: string, label: string, cost: float}|null
	 */
	public static function get_rate( $country, $postcode, $rate_id = '' ) {
		$rates = self::get_rates( $country, $postcode );

		foreach ( $rates as $rate ) {
			if ( $rate['id'] === $rate_id ) {
				return $rate;
			}
		}

		return empty( $rates ) ? null : $rates[0];
	}

	/**
	 * Run the module's own rate calculation for a package built from the cart.
	 *
	 * @param string $country  Destination ISO-2 code.
	 * @param string $postcode Destination post code.
	 * @return array<int, array{id: string, code: string, label: string, cost: float}>
	 */
	private static function calculate( $country, $postcode ) {
		$method = self::get_method();

		if ( ! $method ) {
			return array();
		}

		$cart    = WC()->cart;
		$package = array(
			'contents'        => $cart->get_cart(),
			'contents_cost'   => (float) $cart->get_cart_contents_total(),
			'applied_coupons' => $cart->get_applied_coupons(),
			'user'            => array( 'ID' => get_current_user_id() ),
			'destination'     => array(
				'country'   => $country,
				'state'     => '',
				'postcode'  => $postcode,
				'city'      => '',
				'address'   => '',
				'address_2' => '',
			),
		);

		$method->rates = array();

		// The module prints debug output when its "Debug" option is on; keep it out of the page / AJAX JSON.
		ob_start();

		try {
			$method->calculate_shipping( $package );
		} catch ( Throwable $e ) {
			$method->rates = array();
		}

		ob_end_clean();

		$rates = array();

		foreach ( (array) $method->rates as $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}

			$id      = (string) $rate->get_id();
			$rates[] = array(
				'id'    => $id,
				'code'  => 0 === strpos( $id, 'a1post_' ) ? substr( $id, 7 ) : $id,
				'label' => (string) $rate->get_label(),
				'cost'  => self::add_domestic_leg_fee( (float) $rate->get_cost() ),
			);
		}

		usort(
			$rates,
			function ( $left, $right ) {
				return $left['cost'] <=> $right['cost'];
			}
		);

		return $rates;
	}

	/**
	 * Add the cost of getting the parcel to A1POST (e.g. Econt to Varna).
	 * A rate the module made free stays free.
	 *
	 * @param float $cost A1POST rate.
	 * @return float
	 */
	private static function add_domestic_leg_fee( $cost ) {
		$cost = max( 0.0, $cost );

		if ( $cost > 0 && class_exists( 'A1post_Tariff' ) ) {
			$weight = function_exists( 'WC' ) && WC()->cart ? (float) wc_get_weight( WC()->cart->get_cart_contents_weight(), 'kg' ) : 0.0;
			$cost  += A1post_Tariff::get_domestic_leg_fee( $weight );
		}

		return round( $cost, 2 );
	}

	/**
	 * Shared module instance.
	 *
	 * @return WC_Shipping_Method|null
	 */
	private static function get_method() {
		if ( null === self::$method && self::is_active() ) {
			self::$method = new A1POST_Shipping_Method();
		}

		return self::$method;
	}

	/**
	 * Read one module setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private static function setting( $key ) {
		$method = self::get_method();

		return ( $method && isset( $method->settings[ $key ] ) ) ? $method->settings[ $key ] : null;
	}
}
