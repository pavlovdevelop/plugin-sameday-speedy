<?php
/**
 * Live Speedy delivery price for the exact checkout selection.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quotes the real contract price from Speedy /calculate for the selected
 * office / APS / address city, the cart weight and, for cash on delivery,
 * the collected amount (Speedy's COD fee depends on it).
 */
class Speedy_Live_Quote {

	/**
	 * Transient prefix for cached quotes.
	 */
	const CACHE_PREFIX = 'sameday_spd_quote_';

	/**
	 * Transient prefix for resolved site IDs.
	 */
	const SITE_PREFIX = 'sameday_spd_site_';

	/**
	 * Quote cache lifetime in seconds.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Per-request cache.
	 *
	 * @var array<string, float|null>
	 */
	private static $memory = array();

	/**
	 * Real Speedy price in the store currency, or null when it cannot be quoted
	 * (the caller then falls back to the rate cache / tariff).
	 *
	 * @param string               $service_code speedy_office|speedy_aps|speedy_door.
	 * @param float                $weight       Cart weight in kg.
	 * @param array<string, mixed> $context      Pricing context: speedy_location, speedy_door_city,
	 *                                           postcode, payment_method, cart_total.
	 * @return float|null
	 */
	public static function get_price( $service_code, $weight, $context ) {
		if ( ! class_exists( 'Speedy_Settings' ) || ! Speedy_Settings::has_credentials() ) {
			return null;
		}

		$service_id = self::get_service_id( $service_code );
		$client     = new Speedy_Api_Client( Speedy_Settings::get_credentials() );
		$recipient  = self::build_recipient( $service_code, $context, $client );
		$sender     = self::build_sender();

		if ( $service_id <= 0 || null === $recipient || null === $sender ) {
			return null;
		}

		if ( $weight <= 0 ) {
			$weight = (float) Speedy_Settings::get( 'default_weight', 1 );
		}

		$weight = round( max( 0.1, (float) $weight ), 1 );
		$price  = self::cached_quote( $client, $service_id, $sender, $recipient, $weight, 0.0 );

		if ( null === $price ) {
			return null;
		}

		$goods_amount = self::get_cod_goods_amount( $context );

		if ( $goods_amount > 0 ) {
			$with_cod = self::cached_quote( $client, $service_id, $sender, $recipient, $weight, round( $goods_amount + $price, 2 ) );

			if ( null !== $with_cod ) {
				$price = $with_cod;
			}
		}

		return round( $price, 2 );
	}

	/**
	 * Whether a payment method is cash on delivery (same rule as shipment creation).
	 *
	 * @param string $payment_method Gateway ID.
	 * @return bool
	 */
	public static function is_cod_payment_method( $payment_method ) {
		$method = strtolower( (string) $payment_method );

		if ( '' === $method ) {
			return false;
		}

		return false !== strpos( $method, 'cod' )
			|| in_array( $method, array( 'cheque', 'cheque_payment', 'naloji', 'nalozhen', 'nalozhen_platej' ), true );
	}

	/**
	 * Goods amount collected on delivery, or 0 when not paying cash on delivery.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return float
	 */
	private static function get_cod_goods_amount( $context ) {
		if ( 'yes' !== (string) Speedy_Settings::get( 'cod_on', 'yes' ) ) {
			return 0.0;
		}

		$payment_method = isset( $context['payment_method'] ) ? (string) $context['payment_method'] : '';

		if ( ! self::is_cod_payment_method( $payment_method ) ) {
			return 0.0;
		}

		if ( isset( $context['cart_total'] ) ) {
			return max( 0.0, (float) $context['cart_total'] );
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return max( 0.0, (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax() );
	}

	/**
	 * Speedy service ID for a service code.
	 *
	 * @param string $service_code Service code.
	 * @return int
	 */
	private static function get_service_id( $service_code ) {
		$keys = array(
			'speedy_office' => 'service_id_office',
			'speedy_aps'    => 'service_id_aps',
			'speedy_door'   => 'service_id_door',
		);

		if ( ! isset( $keys[ $service_code ] ) ) {
			return 0;
		}

		$service_id = (int) Speedy_Settings::get( $keys[ $service_code ], 0 );

		return $service_id > 0 ? $service_id : 505;
	}

	/**
	 * Sender block: contract client plus drop-off office when shipping from an office.
	 *
	 * @return array<string, int>|null
	 */
	private static function build_sender() {
		$client_id = (int) Speedy_Settings::get( 'sender_client_id', 0 );

		if ( $client_id <= 0 ) {
			$profile   = Speedy_Settings::get_profile();
			$client_id = isset( $profile['client_id'] ) ? (int) $profile['client_id'] : 0;
		}

		if ( $client_id <= 0 ) {
			return null;
		}

		$sender = array( 'clientId' => $client_id );

		if ( 'office' === (string) Speedy_Settings::get( 'sender_type', 'address' ) && (int) Speedy_Settings::get( 'sender_office_id', 0 ) > 0 ) {
			$sender['dropoffOfficeId'] = (int) Speedy_Settings::get( 'sender_office_id', 0 );
		}

		return $sender;
	}

	/**
	 * Recipient block for the selected destination.
	 *
	 * @param string               $service_code Service code.
	 * @param array<string, mixed> $context      Context.
	 * @param Speedy_Api_Client    $client       API client.
	 * @return array<string, mixed>|null
	 */
	private static function build_recipient( $service_code, $context, $client ) {
		if ( 'speedy_office' === $service_code || 'speedy_aps' === $service_code ) {
			$office_id = isset( $context['speedy_location'] ) ? absint( $context['speedy_location'] ) : 0;

			return $office_id > 0 ? array(
				'privatePerson'  => true,
				'pickupOfficeId' => $office_id,
			) : null;
		}

		if ( 'speedy_door' !== $service_code || empty( $context['speedy_door_city'] ) ) {
			return null;
		}

		$post_code = isset( $context['postcode'] ) ? (string) $context['postcode'] : '';

		if ( '' === $post_code && function_exists( 'WC' ) && WC()->customer ) {
			$post_code = (string) WC()->customer->get_billing_postcode();
		}

		$site_id = self::resolve_site_id( $client, (string) $context['speedy_door_city'], $post_code );

		if ( $site_id <= 0 ) {
			return null;
		}

		return array(
			'privatePerson'   => true,
			'addressLocation' => array(
				'countryId' => 100,
				'siteId'    => $site_id,
			),
		);
	}

	/**
	 * Best-effort Speedy site ID for a free-text city name.
	 *
	 * @param Speedy_Api_Client $client    API client.
	 * @param string            $city      City text.
	 * @param string            $post_code Post code.
	 * @return int 0 when not found.
	 */
	private static function resolve_site_id( $client, $city, $post_code ) {
		$name      = trim( (string) preg_replace( '/^\s*(гр\.?|град|с\.?|село)\s+/iu', '', $city ) );
		$post_code = (string) preg_replace( '/\D/', '', $post_code );
		$upper     = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $name, 'UTF-8' ) : strtoupper( $name );

		if ( '' === $upper ) {
			return 0;
		}

		$cache_key = self::SITE_PREFIX . md5( $upper . '|' . $post_code );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$sites = $client->find_sites( $name );

		if ( is_wp_error( $sites ) ) {
			return 0;
		}

		$exact = array_values(
			array_filter(
				$sites,
				function ( $site ) use ( $upper ) {
					return isset( $site['name'] ) && $upper === $site['name'];
				}
			)
		);

		$site_id = 0;

		foreach ( $exact as $site ) {
			if ( '' !== $post_code && isset( $site['postCode'] ) && $post_code === (string) $site['postCode'] ) {
				$site_id = (int) $site['id'];
				break;
			}
		}

		if ( ! $site_id ) {
			foreach ( $exact as $site ) {
				if ( isset( $site['type'] ) && 'гр.' === $site['type'] ) {
					$site_id = (int) $site['id'];
					break;
				}
			}
		}

		if ( ! $site_id && ! empty( $exact ) ) {
			$site_id = (int) $exact[0]['id'];
		}

		if ( $site_id > 0 ) {
			set_transient( $cache_key, $site_id, WEEK_IN_SECONDS );
		}

		return $site_id;
	}

	/**
	 * Quote with per-request and transient caching.
	 *
	 * @param Speedy_Api_Client    $client     API client.
	 * @param int                  $service_id Speedy service ID.
	 * @param array<string, int>   $sender     Sender.
	 * @param array<string, mixed> $recipient  Recipient.
	 * @param float                $weight     Weight in kg.
	 * @param float                $cod_amount COD amount, 0 for none.
	 * @return float|null Price in the store currency.
	 */
	private static function cached_quote( $client, $service_id, $sender, $recipient, $weight, $cod_amount ) {
		$payer    = (string) Speedy_Settings::get( 'payer_courier', 'SENDER' );
		$currency = get_woocommerce_currency();
		$key      = md5( wp_json_encode( array( $service_id, $sender, $recipient, $weight, $cod_amount, $payer, $currency ) ) );

		if ( array_key_exists( $key, self::$memory ) ) {
			return self::$memory[ $key ];
		}

		$cached = get_transient( self::CACHE_PREFIX . $key );

		if ( false !== $cached ) {
			self::$memory[ $key ] = (float) $cached;

			return self::$memory[ $key ];
		}

		$service = array(
			'serviceIds'           => array( $service_id ),
			'autoAdjustPickupDate' => true,
		);

		if ( $cod_amount > 0 ) {
			$service['additionalServices'] = array(
				'cod' => array(
					'amount'         => $cod_amount,
					'currencyCode'   => $currency,
					'processingType' => 'CASH',
				),
			);
		}

		$response = $client->calculate(
			array(
				'sender'    => $sender,
				'recipient' => $recipient,
				'service'   => $service,
				'content'   => array(
					'parcelsCount' => 1,
					'totalWeight'  => $weight,
				),
				'payment'   => array(
					'courierServicePayer' => $payer,
				),
			)
		);

		$price = null;

		if ( ! is_wp_error( $response ) && ! empty( $response['calculations'][0]['price']['total'] ) ) {
			$amount = (float) $response['calculations'][0]['price']['total'];
			$from   = isset( $response['calculations'][0]['price']['currency'] ) ? strtoupper( (string) $response['calculations'][0]['price']['currency'] ) : 'EUR';
			$price  = self::convert_currency( $amount, $from, $currency );
		}

		// Failures are remembered for this request only, so the next page load retries.
		self::$memory[ $key ] = $price;

		if ( null !== $price ) {
			set_transient( self::CACHE_PREFIX . $key, $price, self::CACHE_TTL );
		}

		return $price;
	}

	/**
	 * Convert between EUR and BGN at the fixed rate.
	 *
	 * @param float  $amount Amount.
	 * @param string $from   Source currency.
	 * @param string $to     Target currency.
	 * @return float|null
	 */
	private static function convert_currency( $amount, $from, $to ) {
		if ( $from === $to ) {
			return $amount;
		}

		if ( 'EUR' === $from && 'BGN' === $to ) {
			return $amount * Sameday_Price_Calculator::BGN_PER_EUR;
		}

		if ( 'BGN' === $from && 'EUR' === $to ) {
			return $amount / Sameday_Price_Calculator::BGN_PER_EUR;
		}

		return null;
	}
}
