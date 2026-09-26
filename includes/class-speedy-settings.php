<?php
/**
 * Speedy API settings storage and helpers.
 *
 * Holds API credentials, defaults for shipment generation and a cached
 * snapshot of the Speedy profile so the admin UI can render contract /
 * client / service info without round-tripping the API on every page load.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Speedy_Settings {

	const OPTION_KEY   = 'sameday_speedy_api_settings';
	const PROFILE_KEY  = 'sameday_speedy_api_profile';
	const SERVICES_KEY = 'sameday_speedy_api_services';
	const OFFICES_KEY  = 'sameday_speedy_api_offices';

	/**
	 * Shipment defaults supported in admin.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'username'           => '',
			'password'           => '',
			'client_system_id'   => '',
			'language'           => 'BG',

			// services map
			'service_id_office'  => '',
			'service_id_aps'     => '',
			'service_id_door'    => '',

			// sender contact
			'sender_name'        => '',
			'sender_phone'       => '',
			'sender_email'       => '',

			// sender source (address vs office)
			'sender_type'        => 'address',
			'sender_client_id'   => '',
			'sender_office_id'   => '',

			// parcel defaults
			'default_parcels'    => 1,
			'default_weight'     => 1.0,
			'default_length'     => 20,
			'default_width'      => 15,
			'default_height'     => 10,
			'default_contents'   => 'Стоки',
			'default_pack'       => 'КУТИЯ',

			// payer defaults
			'payer_courier'      => 'SENDER',
			'payer_declared'     => 'SENDER',
			'payer_cod'          => 'RECIPIENT',

			// extras
			'declared_value_on'  => 'no',
			'cod_on'             => 'yes',
			'fragile_default'    => 'no',
			'saturday_default'   => 'no',

			// print
			'paper_size'         => 'A6',
			'print_copy'         => 'none', // none|same|new
			'pdf_target'         => '_blank',

			// return / OPP (отвори преди да платиш)
			'opp_on'             => 'no',
			'opp_only_cod'       => 'yes',
			'return_payer_opp'   => 'RECIPIENT',
			'return_service_opp' => '',

			'voucher_on'         => 'no',
			'voucher_service_id' => '',
			'voucher_payer'      => 'SENDER',
			'voucher_validity'   => 30,

			// tracking email
			'tracking_email_on'      => 'no',
			'tracking_email_from_name' => '',
			'tracking_email_from_addr' => '',
		);
	}

	/**
	 * Get stored settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Get one setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::get_all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Persist sanitized settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Saved values.
	 */
	public static function save( $input ) {
		$current = self::get_all();
		$clean   = self::sanitize( $input, $current );

		update_option( self::OPTION_KEY, $clean, false );

		return $clean;
	}

	/**
	 * Whether API credentials are set.
	 */
	public static function has_credentials() {
		$all = self::get_all();

		return '' !== (string) $all['username'] && '' !== (string) $all['password'];
	}

	/**
	 * Build credentials payload for the API client.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_credentials() {
		$all = self::get_all();

		return array(
			'userName'       => (string) $all['username'],
			'password'       => (string) $all['password'],
			'language'       => $all['language'] ? (string) $all['language'] : 'BG',
			'clientSystemId' => $all['client_system_id'] ? (int) $all['client_system_id'] : 0,
		);
	}

	/**
	 * Cached Speedy profile (clientId, client record, contract clients).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_profile() {
		$profile = get_option( self::PROFILE_KEY, array() );

		if ( ! is_array( $profile ) ) {
			$profile = array();
		}

		return wp_parse_args(
			$profile,
			array(
				'client_id'        => 0,
				'client'           => array(),
				'contract_clients' => array(),
				'fetched_at'       => 0,
				'last_error'       => '',
			)
		);
	}

	/**
	 * Cached services list (id + name).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_services_cache() {
		$services = get_option( self::SERVICES_KEY, array() );

		if ( ! is_array( $services ) ) {
			$services = array();
		}

		return wp_parse_args(
			$services,
			array(
				'list'       => array(),
				'fetched_at' => 0,
			)
		);
	}

	/**
	 * Persist services snapshot.
	 *
	 * @param array<int, array<string, mixed>> $services Raw services from API.
	 */
	public static function save_services( $services ) {
		$normalized = array();

		foreach ( (array) $services as $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}

			$id = isset( $service['id'] ) ? (int) $service['id'] : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$name = isset( $service['name'] ) ? (string) $service['name'] : '';

			$normalized[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		usort(
			$normalized,
			function ( $a, $b ) {
				return strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		update_option(
			self::SERVICES_KEY,
			array(
				'list'       => $normalized,
				'fetched_at' => time(),
			),
			false
		);
	}

	/**
	 * Cached offices list.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_offices_cache() {
		$offices = get_option( self::OFFICES_KEY, array() );

		if ( ! is_array( $offices ) ) {
			$offices = array();
		}

		return wp_parse_args(
			$offices,
			array(
				'list'       => array(),
				'fetched_at' => 0,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $offices Raw offices from API.
	 */
	public static function save_offices( $offices ) {
		$normalized = array();

		foreach ( (array) $offices as $office ) {
			if ( ! is_array( $office ) ) {
				continue;
			}

			$id = isset( $office['id'] ) ? (int) $office['id'] : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$site_name = isset( $office['address']['siteName'] ) ? (string) $office['address']['siteName'] : '';
			$site_id   = isset( $office['address']['siteId'] ) ? (int) $office['address']['siteId'] : 0;
			$addr      = isset( $office['address']['fullAddressString'] ) ? (string) $office['address']['fullAddressString'] : ( isset( $office['address']['localAddressString'] ) ? (string) $office['address']['localAddressString'] : '' );
			$name      = isset( $office['name'] ) ? (string) $office['name'] : '';

			$label = trim(
				( $site_name ? $site_name . ' — ' : '' )
				. ( $name ? $name . ( $addr ? ' [' . $addr . ']' : '' ) : $addr )
			);

			$normalized[] = array(
				'id'      => $id,
				'name'    => $name,
				'site'    => $site_name,
				'site_id' => $site_id,
				'addr'    => $addr,
				'label'   => $label !== '' ? $label : (string) $id,
			);
		}

		usort(
			$normalized,
			function ( $a, $b ) {
				return strcmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		update_option(
			self::OFFICES_KEY,
			array(
				'list'       => $normalized,
				'fetched_at' => time(),
			),
			false
		);
	}

	/**
	 * Persist profile snapshot.
	 *
	 * @param array<string, mixed> $profile Profile payload.
	 */
	public static function save_profile( $profile ) {
		update_option(
			self::PROFILE_KEY,
			wp_parse_args(
				$profile,
				array(
					'client_id'        => 0,
					'client'           => array(),
					'contract_clients' => array(),
					'fetched_at'       => time(),
					'last_error'       => '',
				)
			),
			false
		);
	}

	/**
	 * Sanitize raw settings input.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param array<string, mixed> $current Current saved data (used for password preservation).
	 * @return array<string, mixed>
	 */
	private static function sanitize( $input, $current ) {
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$payer_choices = array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' );

		$password = isset( $input['password'] ) ? trim( (string) $input['password'] ) : '';

		if ( '' === $password && ! empty( $current['password'] ) ) {
			$password = (string) $current['password'];
		}

		$bool_yes_no = function ( $value ) {
			return 'yes' === $value ? 'yes' : 'no';
		};

		$digits = function ( $value ) {
			return preg_replace( '/[^0-9]/', '', (string) wp_unslash( $value ) );
		};

		$pick = function ( $value, $choices, $default ) {
			$value = is_string( $value ) ? $value : '';
			return in_array( $value, $choices, true ) ? $value : $default;
		};

		return array(
			'username'                 => isset( $input['username'] ) ? sanitize_text_field( wp_unslash( $input['username'] ) ) : $defaults['username'],
			'password'                 => $password,
			'client_system_id'         => isset( $input['client_system_id'] ) ? $digits( $input['client_system_id'] ) : '',
			'language'                 => isset( $input['language'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $input['language'] ) ), 0, 2 ) ) : $defaults['language'],

			'service_id_office'        => isset( $input['service_id_office'] ) ? $digits( $input['service_id_office'] ) : '',
			'service_id_aps'           => isset( $input['service_id_aps'] ) ? $digits( $input['service_id_aps'] ) : '',
			'service_id_door'          => isset( $input['service_id_door'] ) ? $digits( $input['service_id_door'] ) : '',

			'sender_name'              => isset( $input['sender_name'] ) ? sanitize_text_field( wp_unslash( $input['sender_name'] ) ) : $defaults['sender_name'],
			'sender_phone'             => isset( $input['sender_phone'] ) ? sanitize_text_field( wp_unslash( $input['sender_phone'] ) ) : $defaults['sender_phone'],
			'sender_email'             => isset( $input['sender_email'] ) ? sanitize_email( wp_unslash( $input['sender_email'] ) ) : $defaults['sender_email'],

			'sender_type'              => isset( $input['sender_type'] ) ? $pick( $input['sender_type'], array( 'address', 'office' ), $defaults['sender_type'] ) : $defaults['sender_type'],
			'sender_client_id'         => isset( $input['sender_client_id'] ) ? $digits( $input['sender_client_id'] ) : '',
			'sender_office_id'         => isset( $input['sender_office_id'] ) ? $digits( $input['sender_office_id'] ) : '',

			'default_parcels'          => isset( $input['default_parcels'] ) ? max( 1, (int) $input['default_parcels'] ) : $defaults['default_parcels'],
			'default_weight'           => isset( $input['default_weight'] ) ? max( 0.1, (float) wc_format_decimal( $input['default_weight'] ) ) : $defaults['default_weight'],
			'default_length'           => isset( $input['default_length'] ) ? max( 0, (int) $input['default_length'] ) : $defaults['default_length'],
			'default_width'            => isset( $input['default_width'] ) ? max( 0, (int) $input['default_width'] ) : $defaults['default_width'],
			'default_height'           => isset( $input['default_height'] ) ? max( 0, (int) $input['default_height'] ) : $defaults['default_height'],
			'default_contents'         => isset( $input['default_contents'] ) ? sanitize_text_field( wp_unslash( $input['default_contents'] ) ) : $defaults['default_contents'],
			'default_pack'             => isset( $input['default_pack'] ) ? sanitize_text_field( wp_unslash( $input['default_pack'] ) ) : $defaults['default_pack'],

			'payer_courier'            => isset( $input['payer_courier'] ) && in_array( strtoupper( $input['payer_courier'] ), $payer_choices, true ) ? strtoupper( $input['payer_courier'] ) : $defaults['payer_courier'],
			'payer_declared'           => isset( $input['payer_declared'] ) && in_array( strtoupper( $input['payer_declared'] ), $payer_choices, true ) ? strtoupper( $input['payer_declared'] ) : $defaults['payer_declared'],
			'payer_cod'                => isset( $input['payer_cod'] ) && in_array( strtoupper( $input['payer_cod'] ), $payer_choices, true ) ? strtoupper( $input['payer_cod'] ) : $defaults['payer_cod'],

			'declared_value_on'        => $bool_yes_no( isset( $input['declared_value_on'] ) ? $input['declared_value_on'] : '' ),
			'cod_on'                   => $bool_yes_no( isset( $input['cod_on'] ) ? $input['cod_on'] : '' ),
			'fragile_default'          => $bool_yes_no( isset( $input['fragile_default'] ) ? $input['fragile_default'] : '' ),
			'saturday_default'         => $bool_yes_no( isset( $input['saturday_default'] ) ? $input['saturday_default'] : '' ),

			'paper_size'               => isset( $input['paper_size'] ) && in_array( $input['paper_size'], array( 'A4', 'A6', 'A4_4xA6' ), true ) ? $input['paper_size'] : $defaults['paper_size'],
			'print_copy'               => isset( $input['print_copy'] ) ? $pick( $input['print_copy'], array( 'none', 'same', 'new' ), $defaults['print_copy'] ) : $defaults['print_copy'],
			'pdf_target'               => isset( $input['pdf_target'] ) ? $pick( $input['pdf_target'], array( '_blank', '_self' ), $defaults['pdf_target'] ) : $defaults['pdf_target'],

			'opp_on'                   => $bool_yes_no( isset( $input['opp_on'] ) ? $input['opp_on'] : '' ),
			'opp_only_cod'             => $bool_yes_no( isset( $input['opp_only_cod'] ) ? $input['opp_only_cod'] : '' ),
			'return_payer_opp'         => isset( $input['return_payer_opp'] ) && in_array( strtoupper( $input['return_payer_opp'] ), $payer_choices, true ) ? strtoupper( $input['return_payer_opp'] ) : $defaults['return_payer_opp'],
			'return_service_opp'       => isset( $input['return_service_opp'] ) ? $digits( $input['return_service_opp'] ) : '',

			'voucher_on'               => $bool_yes_no( isset( $input['voucher_on'] ) ? $input['voucher_on'] : '' ),
			'voucher_service_id'       => isset( $input['voucher_service_id'] ) ? $digits( $input['voucher_service_id'] ) : '',
			'voucher_payer'            => isset( $input['voucher_payer'] ) && in_array( strtoupper( $input['voucher_payer'] ), $payer_choices, true ) ? strtoupper( $input['voucher_payer'] ) : $defaults['voucher_payer'],
			'voucher_validity'         => isset( $input['voucher_validity'] ) ? max( 1, (int) $input['voucher_validity'] ) : $defaults['voucher_validity'],

			'tracking_email_on'        => $bool_yes_no( isset( $input['tracking_email_on'] ) ? $input['tracking_email_on'] : '' ),
			'tracking_email_from_name' => isset( $input['tracking_email_from_name'] ) ? sanitize_text_field( wp_unslash( $input['tracking_email_from_name'] ) ) : '',
			'tracking_email_from_addr' => isset( $input['tracking_email_from_addr'] ) ? sanitize_email( wp_unslash( $input['tracking_email_from_addr'] ) ) : '',
		);
	}
}
