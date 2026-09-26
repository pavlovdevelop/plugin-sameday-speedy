<?php
/**
 * Speedy rate cache.
 *
 * Pulls live shipping prices from the Speedy `/calculate` endpoint for a
 * curated list of weight bands and stores the result in a WordPress option
 * so checkout can render the correct price instantly without round-tripping
 * the API on every cart update. A WP-Cron job refreshes the cache twice a
 * day, with a manual "Refresh now" button on the Speedy API settings page.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Speedy_Rate_Cache {

	const OPTION_KEY = 'sameday_speedy_rate_cache';
	const CRON_HOOK  = 'speedy_rate_sync_cron';
	const CRON_RECURRENCE = 'twicedaily';

	/**
	 * Fallback Sofia siteId. Used only when no Speedy city is cached.
	 * The sync prefers a real siteId from the cached city list.
	 */
	const REF_SITE_ID = 68134;

	/**
	 * Weight bands (in kg) to query the API for. Checkout looks up the
	 * smallest band whose `max` is greater than the cart weight.
	 *
	 * @return float[]
	 */
	public static function get_bands() {
		return array( 0.5, 1, 2, 3, 4, 5, 6, 7, 8, 10, 12, 15, 20, 25, 30, 40 );
	}

	/**
	 * Read the cache option.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_cache() {
		$cache = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		return wp_parse_args(
			$cache,
			array(
				'fetched_at' => 0,
				'currency'   => 'BGN',
				'rates'      => array(),
				'last_error' => '',
			)
		);
	}

	/**
	 * Save the cache option.
	 *
	 * @param array<string, mixed> $cache Cache payload.
	 */
	public static function save_cache( $cache ) {
		update_option( self::OPTION_KEY, $cache, false );
	}

	/**
	 * Look up a cached price for one service code + weight.
	 *
	 * Returns null when the cache is empty so the caller can fall back to
	 * the hard-coded tariff. The returned amount is in the cache currency
	 * (`get_cache()['currency']`); callers must convert to the store
	 * currency themselves.
	 *
	 * @param string $service_code Service code (speedy_office|speedy_aps|speedy_door).
	 * @param float  $weight       Cart weight in kg.
	 * @return float|null
	 */
	public static function get_rate( $service_code, $weight ) {
		$cache = self::get_cache();

		if ( empty( $cache['rates'][ $service_code ] ) || ! is_array( $cache['rates'][ $service_code ] ) ) {
			return null;
		}

		$weight = max( 0.0, (float) $weight );
		$rates  = $cache['rates'][ $service_code ];

		foreach ( $rates as $band ) {
			if ( ! is_array( $band ) || ! isset( $band['max'], $band['price'] ) ) {
				continue;
			}

			if ( $weight <= (float) $band['max'] ) {
				return (float) $band['price'];
			}
		}

		// Above the largest cached band — extrapolate from the last band so we never
		// undercharge when weight is slightly over the top of the cache.
		$last = end( $rates );

		if ( is_array( $last ) && isset( $last['max'], $last['price'] ) ) {
			$extra = max( 0.0, $weight - (float) $last['max'] );

			return (float) $last['price'] + ( $extra * 0.63 );
		}

		return null;
	}

	/**
	 * Whether the cache is fresh (not older than 24h).
	 */
	public static function is_fresh() {
		$cache = self::get_cache();

		return ! empty( $cache['fetched_at'] ) && ( time() - (int) $cache['fetched_at'] ) < DAY_IN_SECONDS;
	}

	/**
	 * Run a full rate sync. Iterates every (service, weight) tuple and
	 * stores the resulting prices in the cache.
	 *
	 * @param Speedy_Api_Client|null $client Optional pre-built client.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function sync( $client = null ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$client = $client instanceof Speedy_Api_Client
			? $client
			: new Speedy_Api_Client( Speedy_Settings::get_credentials() );

		if ( ! $client->is_configured() ) {
			$existing               = self::get_cache();
			$existing['last_error'] = 'Speedy API не е конфигуриран.';
			self::save_cache( $existing );

			return new WP_Error( 'speedy_not_configured', $existing['last_error'] );
		}

		$sender_client_id = (int) Speedy_Settings::get( 'sender_client_id', 0 );

		if ( $sender_client_id <= 0 ) {
			$profile          = Speedy_Settings::get_profile();
			$sender_client_id = isset( $profile['client_id'] ) ? (int) $profile['client_id'] : 0;
		}

		if ( $sender_client_id <= 0 ) {
			$existing               = self::get_cache();
			$existing['last_error'] = 'Не е намерен clientId на изпращача — обновете профила.';
			self::save_cache( $existing );

			return new WP_Error( 'speedy_no_sender', $existing['last_error'] );
		}

		$service_map = array(
			'speedy_office' => (int) Speedy_Settings::get( 'service_id_office', 0 ),
			'speedy_aps'    => (int) Speedy_Settings::get( 'service_id_aps', 0 ),
			'speedy_door'   => (int) Speedy_Settings::get( 'service_id_door', 0 ),
		);

		$rates    = array();
		$errors   = array();
		$currency = 'BGN';
		$payer    = (string) Speedy_Settings::get( 'payer_courier', 'SENDER' );

		$sample_site_id   = self::resolve_sample_site_id();
		$sample_office_id = self::resolve_sample_office_id();

		if ( $sample_site_id <= 0 ) {
			return new WP_Error(
				'speedy_no_site',
				'Не може да се определи siteId за изпращача. Натиснете „Обнови профила", за да заредим адреса от Speedy.'
			);
		}

		foreach ( $service_map as $code => $service_id ) {
			if ( $service_id <= 0 ) {
				continue;
			}

			$rates[ $code ] = array();

			if ( $sample_site_id <= 0 ) {
				$errors[] = sprintf( '%s: не може да се намери валиден siteId — обновете профила.', $code );
				continue;
			}

			foreach ( self::get_bands() as $weight ) {
				$weight = (float) $weight;

				$payload = array(
					'sender'    => array(
						'clientId' => $sender_client_id,
					),
					'recipient' => array(
						'privatePerson'   => true,
						'addressLocation' => array(
							'countryId' => 100,
							'siteId'    => $sample_site_id,
						),
					),
					'service'   => array(
						'serviceIds'           => array( $service_id ),
						'autoAdjustPickupDate' => true,
					),
					'content'   => array(
						'parcelsCount' => 1,
						'totalWeight'  => $weight,
					),
					'payment'   => array(
						'courierServicePayer' => $payer,
					),
				);

				$response = $client->calculate( $payload );

				if ( is_wp_error( $response ) ) {
					$errors[] = sprintf( '%s @ %.2f kg: %s', $code, $weight, $response->get_error_message() );
					continue;
				}

				$price_amount   = self::extract_price( $response );
				$price_currency = self::extract_currency( $response );

				if ( null === $price_amount ) {
					$errors[] = sprintf( '%s @ %.2f kg: липсва цена в отговора', $code, $weight );
					continue;
				}

				if ( '' !== $price_currency ) {
					$currency = $price_currency;
				}

				$rates[ $code ][] = array(
					'max'   => $weight,
					'price' => round( $price_amount, 4 ),
				);
			}
		}

		$diag = sprintf( '[siteId=%d, officeId=%d, sender=%d]', $sample_site_id, $sample_office_id, $sender_client_id );

		$cache = array(
			'fetched_at' => time(),
			'currency'   => $currency,
			'rates'      => $rates,
			'last_error' => empty( $errors ) ? '' : ( $diag . ' ' . implode( ' | ', $errors ) ),
		);

		self::save_cache( $cache );

		return $cache;
	}

	/**
	 * Schedule the recurring sync hook on activation.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cron hook (called on deactivation).
	 */
	public static function clear_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Wire up the cron handler so WordPress fires our sync. Also makes sure
	 * the recurring event exists, in case the plugin was upgraded without
	 * the activation hook running.
	 */
	public static function register_cron_handler() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_cron();
		}
	}

	/**
	 * Pick a real Sofia / first-cached siteId so /calculate accepts the
	 * recipient location for door-delivery rate lookups.
	 *
	 * @return int
	 */
	private static function resolve_sample_site_id() {
		// Prefer the merchant's OWN siteId from the cached profile.
		// /client/{id} returns it and it's guaranteed to be a real API siteId.
		$profile = Speedy_Settings::get_profile();

		if ( ! empty( $profile['client'] ) && is_array( $profile['client'] ) ) {
			$site_id = self::dig_site_id( $profile['client'] );

			if ( $site_id > 0 ) {
				return $site_id;
			}
		}

		// Then try cached offices (which come from /location/office API).
		$offices = Speedy_Settings::get_offices_cache();

		if ( ! empty( $offices['list'] ) && is_array( $offices['list'] ) ) {
			foreach ( $offices['list'] as $office ) {
				if ( empty( $office['site_id'] ) ) {
					continue;
				}

				if ( ! empty( $office['site'] ) && 0 === stripos( (string) $office['site'], 'софия' ) ) {
					return (int) $office['site_id'];
				}
			}

			foreach ( $offices['list'] as $office ) {
				if ( ! empty( $office['site_id'] ) ) {
					return (int) $office['site_id'];
				}
			}
		}

		// Last-resort fallback: scraped public city ID (may not match API).
		if ( class_exists( 'Speedy_Location_Repository' ) ) {
			$repo   = new Speedy_Location_Repository();
			$cities = $repo->get_all_cities();

			if ( is_array( $cities ) && ! empty( $cities ) ) {
				foreach ( $cities as $city ) {
					if ( ! empty( $city['name'] ) && 0 === stripos( $city['name'], 'софия' ) ) {
						return (int) $city['id'];
					}
				}

				$first = reset( $cities );

				if ( is_array( $first ) && isset( $first['id'] ) ) {
					return (int) $first['id'];
				}
			}
		}

		return self::REF_SITE_ID;
	}

	/**
	 * Look for a siteId anywhere inside a Speedy entity (client / address).
	 *
	 * @param array<string, mixed> $entity Speedy record.
	 * @return int
	 */
	private static function dig_site_id( $entity ) {
		if ( ! is_array( $entity ) ) {
			return 0;
		}

		if ( isset( $entity['siteId'] ) && (int) $entity['siteId'] > 0 ) {
			return (int) $entity['siteId'];
		}

		if ( isset( $entity['address'] ) && is_array( $entity['address'] ) && ! empty( $entity['address']['siteId'] ) ) {
			return (int) $entity['address']['siteId'];
		}

		if ( isset( $entity['addresses'] ) && is_array( $entity['addresses'] ) ) {
			foreach ( $entity['addresses'] as $address ) {
				if ( is_array( $address ) && ! empty( $address['siteId'] ) ) {
					return (int) $address['siteId'];
				}
			}
		}

		return 0;
	}

	/**
	 * Pick the first cached Speedy office. Used as the recipient's pickup
	 * office for office/APS rate lookups so /calculate has a destination.
	 *
	 * @return int
	 */
	private static function resolve_sample_office_id() {
		$cache = Speedy_Settings::get_offices_cache();

		if ( ! empty( $cache['list'] ) && is_array( $cache['list'] ) ) {
			$first = reset( $cache['list'] );

			if ( is_array( $first ) && isset( $first['id'] ) ) {
				return (int) $first['id'];
			}
		}

		return 0;
	}

	/**
	 * Pull the price out of one /calculate response.
	 *
	 * Different Speedy environments wrap the price slightly differently —
	 * sometimes under `calculations[0].price.total`, sometimes under
	 * `calculations[0].totalPriceAmount`, sometimes flat under `price.total`.
	 *
	 * @param array<string, mixed> $response Raw API response.
	 * @return float|null
	 */
	private static function extract_price( $response ) {
		if ( ! is_array( $response ) ) {
			return null;
		}

		if ( ! empty( $response['calculations'] ) && is_array( $response['calculations'] ) ) {
			$calc = $response['calculations'][0];

			if ( is_array( $calc ) ) {
				return self::extract_price_from_calc( $calc );
			}
		}

		if ( isset( $response['price'] ) || isset( $response['totalPriceAmount'] ) || isset( $response['amount'] ) ) {
			return self::extract_price_from_calc( $response );
		}

		return null;
	}

	private static function extract_price_from_calc( $calc ) {
		if ( isset( $calc['price']['total'] ) ) {
			return (float) $calc['price']['total'];
		}

		if ( isset( $calc['price']['amount'] ) ) {
			return (float) $calc['price']['amount'];
		}

		if ( isset( $calc['totalPriceAmount'] ) ) {
			return (float) $calc['totalPriceAmount'];
		}

		if ( isset( $calc['amount'] ) ) {
			return (float) $calc['amount'];
		}

		if ( isset( $calc['totalAmount'] ) ) {
			return (float) $calc['totalAmount'];
		}

		return null;
	}

	private static function extract_currency( $response ) {
		if ( ! is_array( $response ) ) {
			return '';
		}

		if ( ! empty( $response['calculations'][0]['price']['currency'] ) ) {
			return strtoupper( (string) $response['calculations'][0]['price']['currency'] );
		}

		if ( ! empty( $response['calculations'][0]['currencyCode'] ) ) {
			return strtoupper( (string) $response['calculations'][0]['currencyCode'] );
		}

		if ( ! empty( $response['price']['currency'] ) ) {
			return strtoupper( (string) $response['price']['currency'] );
		}

		if ( ! empty( $response['currencyCode'] ) ) {
			return strtoupper( (string) $response['currencyCode'] );
		}

		return '';
	}
}
