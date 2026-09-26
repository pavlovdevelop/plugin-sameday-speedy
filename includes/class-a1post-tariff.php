<?php
/**
 * A1POST international delivery: settings storage and tariff calculation.
 *
 * A1POST does not publish a public REST API - integrations are provisioned by
 * their team per platform. Until we receive API credentials from them, prices
 * are resolved from a zone tariff maintained in the admin, and the shipment
 * itself is created manually in the A1POST portal. The credential fields below
 * are already stored so the API client can be plugged in without a data
 * migration.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A1POST tariff and settings.
 */
class A1post_Tariff {

	/**
	 * Option name.
	 */
	const OPTION = 'sameday_a1post_settings';

	/**
	 * Zone codes in resolution order. The last one is the catch-all.
	 *
	 * @return array<int, string>
	 */
	public static function get_zone_codes() {
		return array( 'eu', 'europe', 'world' );
	}

	/**
	 * Human labels per zone.
	 *
	 * @return array<string, string>
	 */
	public static function get_zone_labels() {
		return array(
			'eu'     => 'Европейски съюз',
			'europe' => 'Останалата част от Европа',
			'world'  => 'Останалият свят',
		);
	}

	/**
	 * Default country lists per zone.
	 *
	 * Bulgaria is intentionally absent - A1POST is offered only for
	 * destinations outside Bulgaria.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function get_default_zone_countries() {
		return array(
			'eu'     => array( 'AT', 'BE', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' ),
			'europe' => array( 'AL', 'AD', 'AM', 'AZ', 'BY', 'BA', 'FO', 'GE', 'GI', 'IS', 'LI', 'MK', 'MD', 'MC', 'ME', 'NO', 'RS', 'RU', 'SM', 'CH', 'TR', 'UA', 'GB', 'VA' ),
			'world'  => array(),
		);
	}

	/**
	 * Default settings.
	 *
	 * The prices below are placeholders so the checkout never offers a
	 * zero-cost international shipment by accident. Replace them with the
	 * agreed A1POST price list from the admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults() {
		$countries = self::get_default_zone_countries();

		return array(
			// Credentials for the future API integration.
			'account_user'    => '',
			'account_pass'    => '',
			'api_key'         => '',
			'api_url'         => A1post_Api_Client::DEFAULT_BASE_URL,
			'sender_name'     => '',
			'sender_phone'    => '',
			'sender_email'    => '',
			'label_format'    => 'pdf_a4',
			'service_code'    => 'L',
			'default_weight_kg' => '1',
			'default_contents' => 'Goods',
			'default_hs_code' => '',
			'ioss'            => '',
			'auto_create_label' => 'no',

			// Checkout behaviour.
			'service_label'   => 'Международна доставка с A1POST',
			'delivery_note'   => 'Доставката се извършва от A1POST до адреса, попълнен в поръчката.',

			// Tariff.
			'zones'           => array(
				'eu'     => array(
					'enabled'         => 'yes',
					'countries'       => $countries['eu'],
					'included_weight' => '1',
					'base'            => '9.99',
					'extra_kg'        => '2.50',
				),
				'europe' => array(
					'enabled'         => 'yes',
					'countries'       => $countries['europe'],
					'included_weight' => '1',
					'base'            => '14.99',
					'extra_kg'        => '3.50',
				),
				'world'  => array(
					'enabled'         => 'yes',
					'countries'       => $countries['world'],
					'included_weight' => '1',
					'base'            => '24.99',
					'extra_kg'        => '5.00',
				),
			),
		);
	}

	/**
	 * Return all settings merged with the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$stored   = get_option( self::OPTION, array() );
		$defaults = self::get_defaults();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, $defaults );

		if ( ! is_array( $settings['zones'] ) ) {
			$settings['zones'] = $defaults['zones'];
		}

		foreach ( self::get_zone_codes() as $zone_code ) {
			if ( ! isset( $settings['zones'][ $zone_code ] ) || ! is_array( $settings['zones'][ $zone_code ] ) ) {
				$settings['zones'][ $zone_code ] = $defaults['zones'][ $zone_code ];
				continue;
			}

			$settings['zones'][ $zone_code ] = wp_parse_args( $settings['zones'][ $zone_code ], $defaults['zones'][ $zone_code ] );

			if ( ! is_array( $settings['zones'][ $zone_code ]['countries'] ) ) {
				$settings['zones'][ $zone_code ]['countries'] = array();
			}
		}

		return $settings;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$settings = self::get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Find the tariff zone that covers one country.
	 *
	 * @param string $country ISO-2 country code.
	 * @return array<string, mixed>|null
	 */
	public static function get_zone_for_country( $country ) {
		$country  = strtoupper( trim( (string) $country ) );
		$settings = self::get_settings();
		$fallback = null;

		if ( '' === $country || 'BG' === $country ) {
			return null;
		}

		foreach ( self::get_zone_codes() as $zone_code ) {
			$zone = $settings['zones'][ $zone_code ];

			if ( 'yes' !== $zone['enabled'] ) {
				continue;
			}

			$countries = array_map( 'strtoupper', array_map( 'strval', (array) $zone['countries'] ) );

			if ( empty( $countries ) ) {
				// A zone without an explicit country list acts as the catch-all.
				if ( null === $fallback ) {
					$zone['code'] = $zone_code;
					$fallback     = $zone;
				}

				continue;
			}

			if ( in_array( $country, $countries, true ) ) {
				$zone['code'] = $zone_code;

				return $zone;
			}
		}

		return $fallback;
	}

	/**
	 * Whether A1POST can quote a price for the destination.
	 *
	 * @param string $country ISO-2 country code.
	 * @return bool
	 */
	public static function supports_country( $country ) {
		return null !== self::get_zone_for_country( $country );
	}

	/**
	 * Calculate the A1POST price in store currency.
	 *
	 * @param string $country ISO-2 country code.
	 * @param float  $weight  Weight in kg.
	 * @return float
	 */
	public static function calculate_price( $country, $weight ) {
		$zone = self::get_zone_for_country( $country );

		if ( null === $zone ) {
			return 0.0;
		}

		$weight          = max( 0.0, (float) $weight );
		$included_weight = max( 0.0, (float) $zone['included_weight'] );
		$price           = max( 0.0, (float) $zone['base'] );

		if ( $weight > $included_weight ) {
			// Started kilograms above the included weight are charged in full.
			$extra_kg = ceil( $weight - $included_weight );
			$price   += $extra_kg * max( 0.0, (float) $zone['extra_kg'] );
		}

		return round( $price, wc_get_price_decimals() );
	}

	/**
	 * Sanitize the admin form payload.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::get_defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$current                = self::get_settings();
		$clean['account_user']  = isset( $input['account_user'] ) ? sanitize_text_field( wp_unslash( $input['account_user'] ) ) : '';
		$clean['account_pass']  = isset( $input['account_pass'] ) ? trim( (string) wp_unslash( $input['account_pass'] ) ) : '';
		if ( '' === $clean['account_pass'] && ! empty( $current['account_pass'] ) ) {
			$clean['account_pass'] = (string) $current['account_pass'];
		}
		$clean['api_key']       = isset( $input['api_key'] ) ? sanitize_text_field( wp_unslash( $input['api_key'] ) ) : '';
		$clean['api_url']       = isset( $input['api_url'] ) ? esc_url_raw( wp_unslash( $input['api_url'] ) ) : '';
		$clean['sender_name']   = isset( $input['sender_name'] ) ? sanitize_text_field( wp_unslash( $input['sender_name'] ) ) : '';
		$clean['sender_phone']  = isset( $input['sender_phone'] ) ? sanitize_text_field( wp_unslash( $input['sender_phone'] ) ) : '';
		$clean['sender_email']  = isset( $input['sender_email'] ) ? sanitize_email( wp_unslash( $input['sender_email'] ) ) : '';
		$clean['label_format']  = isset( $input['label_format'] ) && in_array( $input['label_format'], array( 'pdf_a4', 'pdf_a6', 'zpl' ), true ) ? $input['label_format'] : $defaults['label_format'];
		$clean['service_code']  = isset( $input['service_code'] ) && in_array( $input['service_code'], array( 'L', 'R', 'U', 'ups', 'upsS', 'dhl' ), true ) ? $input['service_code'] : $defaults['service_code'];
		$clean['default_weight_kg'] = self::sanitize_number( isset( $input['default_weight_kg'] ) ? $input['default_weight_kg'] : '', $defaults['default_weight_kg'] );
		$clean['default_contents'] = isset( $input['default_contents'] ) && '' !== trim( (string) $input['default_contents'] ) ? sanitize_text_field( wp_unslash( $input['default_contents'] ) ) : $defaults['default_contents'];
		$clean['default_hs_code'] = isset( $input['default_hs_code'] ) ? sanitize_text_field( wp_unslash( $input['default_hs_code'] ) ) : '';
		$clean['ioss']         = isset( $input['ioss'] ) ? sanitize_text_field( wp_unslash( $input['ioss'] ) ) : '';
		$clean['auto_create_label'] = isset( $input['auto_create_label'] ) && 'yes' === $input['auto_create_label'] ? 'yes' : 'no';
		$clean['service_label'] = isset( $input['service_label'] ) && '' !== trim( (string) $input['service_label'] )
			? sanitize_text_field( wp_unslash( $input['service_label'] ) )
			: $defaults['service_label'];
		$clean['delivery_note'] = isset( $input['delivery_note'] ) ? sanitize_textarea_field( wp_unslash( $input['delivery_note'] ) ) : '';

		$clean['zones'] = array();

		foreach ( self::get_zone_codes() as $zone_code ) {
			$zone_input = isset( $input['zones'][ $zone_code ] ) && is_array( $input['zones'][ $zone_code ] )
				? $input['zones'][ $zone_code ]
				: array();

			$clean['zones'][ $zone_code ] = array(
				'enabled'         => ( isset( $zone_input['enabled'] ) && 'yes' === $zone_input['enabled'] ) ? 'yes' : 'no',
				'countries'       => self::sanitize_country_list( isset( $zone_input['countries'] ) ? $zone_input['countries'] : '' ),
				'included_weight' => self::sanitize_number( isset( $zone_input['included_weight'] ) ? $zone_input['included_weight'] : '', $defaults['zones'][ $zone_code ]['included_weight'] ),
				'base'            => self::sanitize_number( isset( $zone_input['base'] ) ? $zone_input['base'] : '', '0' ),
				'extra_kg'        => self::sanitize_number( isset( $zone_input['extra_kg'] ) ? $zone_input['extra_kg'] : '', '0' ),
			);
		}

		return $clean;
	}

	/**
	 * Turn the textarea / comma list of country codes into a clean array.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public static function sanitize_country_list( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = preg_split( '/[\s,;]+/', (string) wp_unslash( $value ) );
		}

		$countries = array();

		foreach ( (array) $parts as $part ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $part ) );

			if ( 2 !== strlen( $code ) || 'BG' === $code ) {
				continue;
			}

			$countries[ $code ] = $code;
		}

		return array_values( $countries );
	}

	/**
	 * Sanitize a non-negative number kept as a string.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback when empty.
	 * @return string
	 */
	private static function sanitize_number( $value, $fallback ) {
		$value = is_scalar( $value ) ? trim( wp_unslash( (string) $value ) ) : '';

		if ( '' === $value ) {
			return (string) $fallback;
		}

		$normalized = wc_format_decimal( $value, 2 );

		if ( '' === $normalized || (float) $normalized < 0 ) {
			return (string) $fallback;
		}

		return (string) $normalized;
	}
}
