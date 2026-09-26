<?php
/**
 * AJAX handlers for EasyBox lookup.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sameday AJAX handler.
 */
class Sameday_Ajax {

	/**
	 * Location repository instance.
	 *
	 * @var Sameday_Location_Repository
	 */
	private $location_repository;

	/**
	 * Speedy location repository instance.
	 *
	 * @var Speedy_Location_Repository
	 */
	private $speedy_location_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->location_repository        = new Sameday_Location_Repository();
		$this->speedy_location_repository = new Speedy_Location_Repository();

		add_action( 'wp_ajax_get_easybox_cities', array( $this, 'get_easybox_cities' ) );
		add_action( 'wp_ajax_nopriv_get_easybox_cities', array( $this, 'get_easybox_cities' ) );
		add_action( 'wp_ajax_get_easybox_locations', array( $this, 'get_easybox_locations' ) );
		add_action( 'wp_ajax_nopriv_get_easybox_locations', array( $this, 'get_easybox_locations' ) );
		add_action( 'wp_ajax_get_speedy_cities', array( $this, 'get_speedy_cities' ) );
		add_action( 'wp_ajax_nopriv_get_speedy_cities', array( $this, 'get_speedy_cities' ) );
		add_action( 'wp_ajax_get_speedy_locations', array( $this, 'get_speedy_locations' ) );
		add_action( 'wp_ajax_nopriv_get_speedy_locations', array( $this, 'get_speedy_locations' ) );
	}

	/**
	 * Return all EasyBox cities.
	 *
	 * @return void
	 */
	public function get_easybox_cities() {
		check_ajax_referer( 'sameday_ajax_nonce', 'security' );

		$cities = $this->location_repository->get_all_cities();
		$meta   = $this->location_repository->get_sync_meta();

		wp_send_json_success(
			array(
				'cities' => $cities,
				'meta'   => $meta,
			)
		);
	}

	/**
	 * Return EasyBox locations by city and/or search term.
	 *
	 * @return void
	 */
	public function get_easybox_locations() {
		check_ajax_referer( 'sameday_ajax_nonce', 'security' );

		$city   = isset( $_REQUEST['city'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['city'] ) ) : '';
		$search = isset( $_REQUEST['search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search'] ) ) : '';

		$locations = $this->location_repository->search_locations( $search, $city );

		wp_send_json_success(
			array(
				'locations' => array_map(
					array( $this, 'format_location_payload' ),
					$locations
				),
				'total'     => count( $locations ),
			)
		);
	}

	/**
	 * Return all official Speedy cities.
	 *
	 * @return void
	 */
	public function get_speedy_cities() {
		check_ajax_referer( 'sameday_ajax_nonce', 'security' );

		$cities = $this->speedy_location_repository->get_all_cities();
		$meta   = $this->speedy_location_repository->get_sync_meta();

		wp_send_json_success(
			array(
				'cities' => $cities,
				'meta'   => $meta,
			)
		);
	}

	/**
	 * Return official Speedy locations for one city and type.
	 *
	 * @return void
	 */
	public function get_speedy_locations() {
		check_ajax_referer( 'sameday_ajax_nonce', 'security' );

		$city_id = isset( $_REQUEST['city_id'] ) ? absint( wp_unslash( $_REQUEST['city_id'] ) ) : 0;
		$type    = isset( $_REQUEST['type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['type'] ) ) : 'all';

		if ( $city_id <= 0 ) {
			wp_send_json_success(
				array(
					'locations' => array(),
					'total'     => 0,
					'message'   => 'Първо изберете населено място.',
				)
			);
		}

		$locations = $this->speedy_location_repository->get_locations_by_city( $city_id, $type );

		wp_send_json_success(
			array(
				'locations' => array_map(
					array( $this, 'format_speedy_location_payload' ),
					$locations
				),
				'total'     => count( $locations ),
			)
		);
	}

	/**
	 * Format one location for the frontend dropdown.
	 *
	 * @param array<string, mixed> $location One location.
	 * @return array<string, mixed>
	 */
	private function format_location_payload( $location ) {
		return array(
			'id'          => (int) $location['id'],
			'name'        => (string) $location['name'],
			'city'        => (string) $location['city'],
			'county'      => (string) $location['county'],
			'address'     => (string) $location['address'],
			'postal_code' => (string) $location['postal_code'],
			'label'       => (string) $location['label'],
			'details'     => (string) $location['details'],
		);
	}

	/**
	 * Format one Speedy location for the frontend dropdown.
	 *
	 * @param array<string, mixed> $location One location.
	 * @return array<string, mixed>
	 */
	private function format_speedy_location_payload( $location ) {
		return array(
			'id'         => (int) $location['id'],
			'type'       => (string) $location['type'],
			'name'       => (string) $location['name'],
			'city'       => (string) $location['city'],
			'city_id'    => (int) $location['city_id'],
			'city_label' => (string) $location['city_label'],
			'address'    => (string) $location['address'],
			'label'      => (string) $location['label'],
			'details'    => (string) $location['details'],
			'map_url'    => (string) $location['map_url'],
		);
	}
}
