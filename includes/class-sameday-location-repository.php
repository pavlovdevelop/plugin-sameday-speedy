<?php
/**
 * Official EasyBox location repository.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EasyBox location repository backed by the official Sameday source.
 */
class Sameday_Location_Repository {

	/**
	 * Cached locations option key.
	 */
	const OPTION_KEY = 'sameday_easybox_locations';

	/**
	 * Cached sync metadata option key.
	 */
	const META_OPTION_KEY = 'sameday_easybox_locations_meta';

	/**
	 * Official Sameday endpoint used by their public EasyBox map.
	 */
	const REMOTE_URL = 'https://sameday.bg/wp/wp-admin/admin-ajax.php?action=get_all_ooh_request&country=Bulgaria';

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 43200;

	/**
	 * Get all cached EasyBox locations.
	 *
	 * @param bool $force_refresh Whether to force sync with the remote source.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_locations( $force_refresh = false ) {
		$locations = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $locations ) ) {
			$locations = array();
		}

		if ( $force_refresh || $this->needs_refresh( $locations ) ) {
			$refreshed = $this->sync_locations();

			if ( ! is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		return $locations;
	}

	/**
	 * Get one EasyBox location by ID.
	 *
	 * @param int $location_id Location ID.
	 * @return array<string, mixed>|null
	 */
	public function get_location_by_id( $location_id ) {
		$location_id = absint( $location_id );

		if ( $location_id <= 0 ) {
			return null;
		}

		foreach ( $this->get_all_locations() as $location ) {
			if ( isset( $location['id'] ) && (int) $location['id'] === $location_id ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Get all EasyBox locations in a city.
	 *
	 * @param string $city   City name.
	 * @param string $search Optional search term.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_locations_by_city( $city, $search = '' ) {
		return $this->filter_locations( $this->get_all_locations(), $city, $search );
	}

	/**
	 * Search EasyBox locations by city and/or search term.
	 *
	 * @param string $search Search term.
	 * @param string $city   Optional city.
	 * @return array<int, array<string, mixed>>
	 */
	public function search_locations( $search = '', $city = '' ) {
		return $this->filter_locations( $this->get_all_locations(), $city, $search );
	}

	/**
	 * Get all cities with location counts.
	 *
	 * @return array<int, array{name:string,count:int}>
	 */
	public function get_all_cities() {
		$cities = array();

		foreach ( $this->get_all_locations() as $location ) {
			$city = isset( $location['city'] ) ? (string) $location['city'] : '';

			if ( '' === $city ) {
				continue;
			}

			if ( ! isset( $cities[ $city ] ) ) {
				$cities[ $city ] = array(
					'name'  => $city,
					'count' => 0,
				);
			}

			$cities[ $city ]['count']++;
		}

		uasort(
			$cities,
			function ( $left, $right ) {
				return $this->compare_strings( $left['name'], $right['name'] );
			}
		);

		return array_values( $cities );
	}

	/**
	 * Get sync metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_sync_meta() {
		$meta = get_option( self::META_OPTION_KEY, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		return wp_parse_args(
			$meta,
			array(
				'synced_at'   => 0,
				'count'       => 0,
				'cities_count'=> 0,
				'source_url'  => self::REMOTE_URL,
				'last_error'  => '',
			)
		);
	}

	/**
	 * Sync all EasyBox locations from the official Sameday source.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function sync_locations() {
		$locations = $this->fetch_remote_locations();

		if ( is_wp_error( $locations ) ) {
			$meta               = $this->get_sync_meta();
			$meta['last_error'] = $locations->get_error_message();
			update_option( self::META_OPTION_KEY, $meta, false );

			return $locations;
		}

		update_option( self::OPTION_KEY, $locations, false );
		update_option(
			self::META_OPTION_KEY,
			array(
				'synced_at'    => time(),
				'count'        => count( $locations ),
				'cities_count' => count( $this->get_city_map( $locations ) ),
				'source_url'   => self::REMOTE_URL,
				'last_error'   => '',
			),
			false
		);

		return $locations;
	}

	/**
	 * Check whether cached data should be refreshed.
	 *
	 * @param array<int, array<string, mixed>> $locations Cached locations.
	 * @return bool
	 */
	private function needs_refresh( $locations ) {
		$meta = $this->get_sync_meta();

		if ( empty( $locations ) ) {
			return true;
		}

		if ( empty( $meta['synced_at'] ) ) {
			return true;
		}

		return ( time() - (int) $meta['synced_at'] ) >= self::CACHE_TTL;
	}

	/**
	 * Fetch and normalize locations from the official source.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function fetch_remote_locations() {
		$response = wp_remote_get(
			self::REMOTE_URL,
			array(
				'timeout'    => 30,
				'headers'    => array(
					'Accept' => 'application/json',
				),
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sameday_easybox_request_failed', sprintf( 'Неуспешна връзка към Sameday: %s', $response->get_error_message() ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code || '' === $body ) {
			return new WP_Error( 'sameday_easybox_bad_response', 'Sameday върна невалиден отговор за EasyBox локациите.' );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return new WP_Error( 'sameday_easybox_invalid_payload', 'Неуспешно разчитане на официалния списък с EasyBox автомати.' );
		}

		$locations = array();

		foreach ( $decoded['data'] as $raw_location ) {
			if ( ! is_array( $raw_location ) ) {
				continue;
			}

			if ( ! isset( $raw_location['type'] ) || 'locker' !== $raw_location['type'] ) {
				continue;
			}

			if ( isset( $raw_location['clientVisible'] ) && false === $raw_location['clientVisible'] ) {
				continue;
			}

			$location = $this->normalize_location( $raw_location );

			if ( empty( $location ) || empty( $location['id'] ) ) {
				continue;
			}

			$locations[ $location['id'] ] = $location;
		}

		$locations = array_values( $locations );

		usort(
			$locations,
			function ( $left, $right ) {
				$by_city = $this->compare_strings( $left['city'], $right['city'] );

				if ( 0 !== $by_city ) {
					return $by_city;
				}

				$by_name = $this->compare_strings( $left['name'], $right['name'] );

				if ( 0 !== $by_name ) {
					return $by_name;
				}

				return $this->compare_strings( $left['address'], $right['address'] );
			}
		);

		return $locations;
	}

	/**
	 * Normalize one remote location payload.
	 *
	 * @param array<string, mixed> $raw_location Remote location.
	 * @return array<string, mixed>
	 */
	private function normalize_location( $raw_location ) {
		$id      = isset( $raw_location['oohId'] ) ? absint( $raw_location['oohId'] ) : 0;
		$name    = isset( $raw_location['name'] ) ? sanitize_text_field( $raw_location['name'] ) : '';
		$city    = isset( $raw_location['city'] ) ? sanitize_text_field( $raw_location['city'] ) : '';
		$county  = isset( $raw_location['county'] ) ? sanitize_text_field( $raw_location['county'] ) : '';
		$address = isset( $raw_location['address'] ) ? sanitize_text_field( $raw_location['address'] ) : '';

		if ( $id <= 0 || '' === $name || '' === $city || '' === $address ) {
			return array();
		}

		$postal_code = isset( $raw_location['postalCode'] ) ? sanitize_text_field( $raw_location['postalCode'] ) : '';
		$label       = $name . ' - ' . $address;
		$details     = $name . ', ' . $city . ', ' . $address;

		return array(
			'id'                => $id,
			'ooh_id'            => $id,
			'type'              => 'locker',
			'name'              => $name,
			'city'              => $city,
			'county'            => $county,
			'address'           => $address,
			'postal_code'       => $postal_code,
			'label'             => $label,
			'details'           => $details,
			'lat'               => isset( $raw_location['lat'] ) ? (string) $raw_location['lat'] : '',
			'lng'               => isset( $raw_location['lng'] ) ? (string) $raw_location['lng'] : '',
			'supported_payment' => ! empty( $raw_location['supportedPayment'] ) ? 1 : 0,
			'search_text'       => $this->normalize_for_search(
				implode(
					' ',
					array_filter(
						array(
							$name,
							$address,
							$city,
							$county,
							$postal_code,
						)
					)
				)
			),
		);
	}

	/**
	 * Filter locations by city and/or search term.
	 *
	 * @param array<int, array<string, mixed>> $locations Locations list.
	 * @param string                           $city      Optional city.
	 * @param string                           $search    Optional search.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_locations( $locations, $city = '', $search = '' ) {
		$city         = sanitize_text_field( $city );
		$search       = sanitize_text_field( $search );
		$search_value = $this->normalize_for_search( $search );
		$filtered     = array();

		foreach ( $locations as $location ) {
			if ( '' !== $city && $city !== $location['city'] ) {
				continue;
			}

			if ( '' !== $search_value && false === strpos( $location['search_text'], $search_value ) ) {
				continue;
			}

			$filtered[] = $location;
		}

		return array_values( $filtered );
	}

	/**
	 * Get city map for metadata counts.
	 *
	 * @param array<int, array<string, mixed>> $locations Locations list.
	 * @return array<string, bool>
	 */
	private function get_city_map( $locations ) {
		$cities = array();

		foreach ( $locations as $location ) {
			if ( empty( $location['city'] ) ) {
				continue;
			}

			$cities[ $location['city'] ] = true;
		}

		return $cities;
	}

	/**
	 * Normalize value for basic search matching.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_for_search( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = remove_accents( $value );

		if ( function_exists( 'mb_strtolower' ) ) {
			$value = mb_strtolower( $value, 'UTF-8' );
		} else {
			$value = strtolower( $value );
		}

		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Compare strings safely for sorting.
	 *
	 * @param string $left  Left value.
	 * @param string $right Right value.
	 * @return int
	 */
	private function compare_strings( $left, $right ) {
		$left  = $this->normalize_for_search( $left );
		$right = $this->normalize_for_search( $right );

		if ( $left === $right ) {
			return 0;
		}

		return ( $left < $right ) ? -1 : 1;
	}
}
