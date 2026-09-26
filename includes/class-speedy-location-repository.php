<?php
/**
 * Official Speedy office and APS repository.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Speedy location repository backed by the official Speedy public pages.
 */
class Speedy_Location_Repository {

	/**
	 * Cached Speedy cities option key.
	 */
	const CITIES_OPTION_KEY = 'sameday_speedy_cities';

	/**
	 * Cached Speedy cities metadata option key.
	 */
	const META_OPTION_KEY = 'sameday_speedy_cities_meta';

	/**
	 * Transient prefix for one city payload.
	 */
	const CITY_TRANSIENT_PREFIX = 'sameday_spd_city_';

	/**
	 * Official Speedy page with all offices and APS city filters.
	 */
	const BASE_URL = 'https://www.speedy.bg/bg/speedy-offices-automats';

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 43200;

	/**
	 * Remote request timeout in seconds.
	 */
	const REQUEST_TIMEOUT = 90;

	/**
	 * Maximum retry attempts for one remote request.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Delay between retries in microseconds.
	 */
	const RETRY_DELAY_US = 500000;

	/**
	 * Get all Speedy cities.
	 *
	 * @param bool $force_refresh Whether to force refresh.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_cities( $force_refresh = false ) {
		$cities = get_option( self::CITIES_OPTION_KEY, array() );

		if ( ! is_array( $cities ) ) {
			$cities = array();
		}

		if ( $force_refresh || $this->needs_city_refresh( $cities ) ) {
			$refreshed = $this->sync_cities();

			if ( ! is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		return $cities;
	}

	/**
	 * Get one location by city, ID, and optional type.
	 *
	 * @param int    $city_id     City/site ID.
	 * @param int    $location_id Location ID.
	 * @param string $type        office|aps|all.
	 * @return array<string, mixed>|null
	 */
	public function get_location_by_id( $city_id, $location_id, $type = 'all' ) {
		$city_id     = absint( $city_id );
		$location_id = absint( $location_id );

		if ( $city_id <= 0 || $location_id <= 0 ) {
			return null;
		}

		foreach ( $this->get_locations_by_city( $city_id, $type ) as $location ) {
			if ( isset( $location['id'] ) && (int) $location['id'] === $location_id ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Get Speedy locations for one city and service type.
	 *
	 * @param int    $city_id City/site ID.
	 * @param string $type    office|aps|all.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_locations_by_city( $city_id, $type = 'all' ) {
		$city_id = absint( $city_id );
		$type    = $this->normalize_type( $type );

		if ( $city_id <= 0 ) {
			return array();
		}

		$transient_key = self::CITY_TRANSIENT_PREFIX . $city_id;
		$locations     = get_transient( $transient_key );

		if ( ! is_array( $locations ) ) {
			$locations = $this->fetch_city_locations( $city_id );

			if ( is_wp_error( $locations ) ) {
				return array();
			}

			set_transient( $transient_key, $locations, self::CACHE_TTL );
		}

		if ( 'all' === $type ) {
			return $locations;
		}

		return array_values(
			array_filter(
				$locations,
				function ( $location ) use ( $type ) {
					return isset( $location['type'] ) && $type === $location['type'];
				}
			)
		);
	}

	/**
	 * Get sync metadata for the city list.
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
				'synced_at'       => 0,
				'count'           => 0,
				'cities_count'    => 0,
				'locations_count' => 0,
				'offices_count'   => 0,
				'aps_count'       => 0,
				'source_url'      => self::BASE_URL,
				'last_error'      => '',
			)
		);
	}

	/**
	 * Sync all Speedy cities plus all office and APS pages.
	 *
	 * @return array<string, int>|WP_Error
	 */
	public function sync_locations() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$cities_result = $this->sync_cities();
		$cities        = $cities_result;

		if ( is_wp_error( $cities_result ) ) {
			$cities = get_option( self::CITIES_OPTION_KEY, array() );

			if ( ! is_array( $cities ) || empty( $cities ) ) {
				return $cities_result;
			}
		}

		$offices_count   = 0;
		$aps_count       = 0;
		$locations_count = 0;
		$fallback_cities = array();
		$skipped_cities  = array();

		foreach ( $cities as $city ) {
			if ( empty( $city['id'] ) ) {
				continue;
			}

			$city_id   = (int) $city['id'];
			$locations = $this->fetch_city_locations( $city_id );

			if ( is_wp_error( $locations ) ) {
				$cached_locations = get_transient( self::CITY_TRANSIENT_PREFIX . $city_id );

				if ( is_array( $cached_locations ) && ! empty( $cached_locations ) ) {
					$locations = $cached_locations;
					$fallback_cities[] = ! empty( $city['label'] ) ? (string) $city['label'] : (string) $city_id;
				} else {
					$skipped_cities[] = ! empty( $city['label'] ) ? (string) $city['label'] : (string) $city_id;
					continue;
				}
			}

			set_transient( self::CITY_TRANSIENT_PREFIX . $city_id, $locations, self::CACHE_TTL );
			$locations_count += count( $locations );

			foreach ( $locations as $location ) {
				if ( isset( $location['type'] ) && 'aps' === $location['type'] ) {
					$aps_count++;
				} else {
					$offices_count++;
				}
			}
		}

		$warning = '';

		if ( ! empty( $fallback_cities ) || ! empty( $skipped_cities ) ) {
			$parts = array();

			if ( ! empty( $fallback_cities ) ) {
				$parts[] = sprintf( 'Използван е кеш за %d населени места', count( $fallback_cities ) );
			}

			if ( ! empty( $skipped_cities ) ) {
				$parts[] = sprintf( 'пропуснати са %d населени места без кеш', count( $skipped_cities ) );
			}

			$warning = implode( '; ', $parts ) . '.';
		}

		update_option(
			self::META_OPTION_KEY,
			array(
				'synced_at'       => time(),
				'count'           => count( $cities ),
				'cities_count'    => count( $cities ),
				'locations_count' => $locations_count,
				'offices_count'   => $offices_count,
				'aps_count'       => $aps_count,
				'source_url'      => self::BASE_URL,
				'last_error'      => $warning,
			),
			false
		);

		return array(
			'cities_count'    => count( $cities ),
			'locations_count' => $locations_count,
			'offices_count'   => $offices_count,
			'aps_count'       => $aps_count,
		);
	}

	/**
	 * Sync Speedy city list from the official page.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function sync_cities() {
		$cities = $this->fetch_remote_cities();

		if ( is_wp_error( $cities ) ) {
			$cached_cities = get_option( self::CITIES_OPTION_KEY, array() );
			$meta          = $this->get_sync_meta();
			$meta['last_error'] = $cities->get_error_message();
			update_option( self::META_OPTION_KEY, $meta, false );

			if ( is_array( $cached_cities ) && ! empty( $cached_cities ) ) {
				return $cached_cities;
			}

			return $cities;
		}

		update_option( self::CITIES_OPTION_KEY, $cities, false );
		update_option(
			self::META_OPTION_KEY,
			array(
				'synced_at'       => time(),
				'count'           => count( $cities ),
				'cities_count'    => count( $cities ),
				'locations_count' => 0,
				'offices_count'   => 0,
				'aps_count'       => 0,
				'source_url'      => self::BASE_URL,
				'last_error'      => '',
			),
			false
		);

		return $cities;
	}

	/**
	 * Whether city cache should be refreshed.
	 *
	 * @param array<int, array<string, mixed>> $cities Cached cities.
	 * @return bool
	 */
	private function needs_city_refresh( $cities ) {
		$meta = $this->get_sync_meta();

		if ( empty( $cities ) ) {
			return true;
		}

		if ( empty( $meta['synced_at'] ) ) {
			return true;
		}

		return ( time() - (int) $meta['synced_at'] ) >= self::CACHE_TTL;
	}

	/**
	 * Fetch Speedy cities from the official page.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function fetch_remote_cities() {
		$body = $this->request_html(
			self::BASE_URL,
			'speedy_cities_request_failed',
			'Speedy върна невалиден отговор за списъка с населени места.'
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$document = $this->create_document( $body );

		if ( ! $document ) {
			return new WP_Error( 'speedy_cities_invalid_html', 'Неуспешно разчитане на официалната страница на Speedy.' );
		}

		$xpath  = new DOMXPath( $document );
		$nodes  = $xpath->query( '//select[starts-with(@id,"offices_list_choose_city_")]//option[@value != ""]' );
		$cities = array();

		if ( ! $nodes ) {
			return new WP_Error( 'speedy_cities_missing_select', 'Не е намерен официалният списък с населени места на Speedy.' );
		}

		foreach ( $nodes as $node ) {
			$city_id = absint( $node->getAttribute( 'value' ) );
			$label   = $this->clean_text( $node->textContent );

			if ( $city_id <= 0 || '' === $label ) {
				continue;
			}

			$cities[ $city_id ] = array(
				'id'    => $city_id,
				'name'  => $this->extract_city_name( $label ),
				'label' => $label,
			);
		}

		$cities = array_values( $cities );

		usort(
			$cities,
			function ( $left, $right ) {
				return $this->compare_strings( $left['name'], $right['name'] );
			}
		);

		return $cities;
	}

	/**
	 * Fetch one city's Speedy offices and APS from the official page.
	 *
	 * @param int $city_id City/site ID.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function fetch_city_locations( $city_id ) {
		$city_id  = absint( $city_id );
		$city_map = $this->get_city_map();

		if ( $city_id <= 0 || ! isset( $city_map[ $city_id ] ) ) {
			return new WP_Error( 'speedy_locations_invalid_city', 'Невалидно населено място за Speedy.' );
		}

		$body = $this->request_html(
			add_query_arg(
				array(
					'city' => $city_id,
				),
				self::BASE_URL
			),
			'speedy_locations_request_failed',
			'Speedy върна невалиден отговор за локациите.'
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$document = $this->create_document( $body );

		if ( ! $document ) {
			return new WP_Error( 'speedy_locations_invalid_html', 'Неуспешно разчитане на официалния списък с офиси и АПС на Speedy.' );
		}

		$xpath     = new DOMXPath( $document );
		$box_nodes = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " office-box ")]' );
		$locations = array();

		if ( ! $box_nodes ) {
			return array();
		}

		foreach ( $box_nodes as $box_node ) {
			$class_name = '';
			$class_attr = $box_node->attributes ? $box_node->attributes->getNamedItem( 'class' ) : null;

			if ( $class_attr ) {
				$class_name = (string) $class_attr->nodeValue;
			}

			$type    = false !== strpos( $class_name, 'type-apt' ) ? 'aps' : 'office';
			$id      = absint( $this->query_single_text( $xpath, './/span[contains(concat(" ", normalize-space(@class), " "), " office-id ")]', $box_node ) );
			$name    = $this->query_single_text( $xpath, './/p[contains(concat(" ", normalize-space(@class), " "), " office-name ")]', $box_node );
			$address = $this->extract_address_text( $xpath, $box_node );
			$map_url = $this->extract_map_url( $xpath, $box_node );

			if ( $id <= 0 || '' === $name || '' === $address ) {
				continue;
			}

			$label = $name . ' - ' . $address;

			$locations[ $id ] = array(
				'id'         => $id,
				'type'       => $type,
				'name'       => $name,
				'city_id'    => $city_id,
				'city'       => $city_map[ $city_id ]['name'],
				'city_label' => $city_map[ $city_id ]['label'],
				'address'    => $address,
				'label'      => $label,
				'details'    => $name . ', ' . $address,
				'map_url'    => $map_url,
			);
		}

		$locations = array_values( $locations );

		usort(
			$locations,
			function ( $left, $right ) {
				$by_type = $this->compare_strings( $left['type'], $right['type'] );

				if ( 0 !== $by_type ) {
					return $by_type;
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
	 * Perform one remote HTML request with retries.
	 *
	 * @param string $url             Request URL.
	 * @param string $error_code      WP_Error code.
	 * @param string $invalid_message Message for invalid responses.
	 * @return string|WP_Error
	 */
	private function request_html( $url, $error_code, $invalid_message ) {
		$last_error = '';

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => self::REQUEST_TIMEOUT,
					'redirection' => 5,
					'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
					'headers'     => array(
						'Accept-Language' => 'bg-BG,bg;q=0.9,en;q=0.8',
						'Connection'      => 'close',
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$status_code = (int) wp_remote_retrieve_response_code( $response );
				$body        = wp_remote_retrieve_body( $response );

				if ( 200 === $status_code && '' !== $body ) {
					return $body;
				}

				$last_error = $invalid_message;
			} else {
				$last_error = sprintf( 'Неуспешна връзка към Speedy: %s', $response->get_error_message() );
			}

			if ( $attempt < self::MAX_RETRIES && function_exists( 'usleep' ) ) {
				usleep( self::RETRY_DELAY_US );
			}
		}

		return new WP_Error( $error_code, $last_error ? $last_error : $invalid_message );
	}

	/**
	 * Create a DOMDocument from one HTML string.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	private function create_document( $html ) {
		if ( '' === $html ) {
			return null;
		}

		$previous_state = libxml_use_internal_errors( true );
		$document       = new DOMDocument();
		$loaded         = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		if ( ! $loaded ) {
			return null;
		}

		return $document;
	}

	/**
	 * Extract address paragraph text from one location box.
	 *
	 * @param DOMXPath   $xpath XPath instance.
	 * @param DOMElement $node  Box node.
	 * @return string
	 */
	private function extract_address_text( $xpath, $node ) {
		$paragraphs = $xpath->query( './/p[strong]', $node );

		if ( ! $paragraphs ) {
			return '';
		}

		foreach ( $paragraphs as $paragraph ) {
			$text = $this->clean_text( $paragraph->textContent );

			if ( 0 !== strpos( $text, 'Адрес' ) ) {
				continue;
			}

			$text = trim( preg_replace( '/^Адрес\s*/u', '', $text ) );

			return $text;
		}

		return '';
	}

	/**
	 * Extract map link from one location box.
	 *
	 * @param DOMXPath   $xpath XPath instance.
	 * @param DOMElement $node  Box node.
	 * @return string
	 */
	private function extract_map_url( $xpath, $node ) {
		$link = $xpath->query( './/a[contains(concat(" ", normalize-space(@class), " "), " btnPrimary ")]', $node );

		if ( ! $link || ! $link->length ) {
			return '';
		}

		return esc_url_raw( $link->item( 0 )->getAttribute( 'href' ) );
	}

	/**
	 * Query one text value with XPath.
	 *
	 * @param DOMXPath     $xpath XPath instance.
	 * @param string       $query XPath query.
	 * @param DOMNode|null $node  Context node.
	 * @return string
	 */
	private function query_single_text( $xpath, $query, $node = null ) {
		$results = $xpath->query( $query, $node );

		if ( ! $results || ! $results->length ) {
			return '';
		}

		return $this->clean_text( $results->item( 0 )->textContent );
	}

	/**
	 * Build one map keyed by city ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_city_map() {
		$city_map = array();

		foreach ( $this->get_all_cities() as $city ) {
			if ( empty( $city['id'] ) ) {
				continue;
			}

			$city_map[ (int) $city['id'] ] = $city;
		}

		return $city_map;
	}

	/**
	 * Normalize one type.
	 *
	 * @param string $type office|aps|all.
	 * @return string
	 */
	private function normalize_type( $type ) {
		if ( 'office' === $type || 'aps' === $type ) {
			return $type;
		}

		return 'all';
	}

	/**
	 * Extract city name from the official Speedy option label.
	 *
	 * @param string $label Official label.
	 * @return string
	 */
	private function extract_city_name( $label ) {
		if ( preg_match( '/^([^\(\[]+)/u', $label, $matches ) ) {
			return trim( $matches[1] );
		}

		return $label;
	}

	/**
	 * Cleanup one text string.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function clean_text( $text ) {
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Compare two strings for stable sorting.
	 *
	 * @param string $left  Left value.
	 * @param string $right Right value.
	 * @return int
	 */
	private function compare_strings( $left, $right ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			$left  = mb_strtolower( (string) $left, 'UTF-8' );
			$right = mb_strtolower( (string) $right, 'UTF-8' );
		} else {
			$left  = strtolower( (string) $left );
			$right = strtolower( (string) $right );
		}

		if ( $left === $right ) {
			return 0;
		}

		return ( $left < $right ) ? -1 : 1;
	}
}
