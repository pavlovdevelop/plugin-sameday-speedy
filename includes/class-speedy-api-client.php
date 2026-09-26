<?php
/**
 * Speedy REST API client.
 *
 * Wraps the public Speedy Web API (https://api.speedy.bg/v1/) with the
 * helpers we need to talk to the carrier from WooCommerce: profile lookup,
 * services, rate calculation, shipment create / update / cancel and
 * waybill / voucher printing.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Speedy_Api_Client {

	const BASE_URL = 'https://api.speedy.bg/v1';

	const REQUEST_TIMEOUT = 45;

	/**
	 * @var array<string, mixed>
	 */
	private $credentials;

	/**
	 * @param array<string, mixed> $credentials Username / password / clientSystemId / language.
	 */
	public function __construct( $credentials = array() ) {
		$this->credentials = wp_parse_args(
			$credentials,
			array(
				'userName'       => '',
				'password'       => '',
				'language'       => 'BG',
				'clientSystemId' => 0,
			)
		);
	}

	/**
	 * Whether minimum credentials are present.
	 */
	public function is_configured() {
		return '' !== (string) $this->credentials['userName']
			&& '' !== (string) $this->credentials['password'];
	}

	/**
	 * POST /client - return the authenticated user's client ID.
	 *
	 * @return int|WP_Error
	 */
	public function get_own_client_id() {
		$response = $this->post( '/client', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['clientId'] ) ) {
			return new WP_Error( 'speedy_no_client_id', 'Speedy не върна clientId за този профил.' );
		}

		return (int) $response['clientId'];
	}

	/**
	 * POST /client/{id} - full client record.
	 *
	 * @param int $client_id Client ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_client( $client_id ) {
		$client_id = absint( $client_id );

		if ( $client_id <= 0 ) {
			return new WP_Error( 'speedy_invalid_client_id', 'Невалидно clientId за Speedy.' );
		}

		$response = $this->post( '/client/' . $client_id, array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['client'] ) ) {
			return (array) $response['client'];
		}

		return $response;
	}

	/**
	 * POST /client/contract - all clients sharing one contract.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_contract_clients() {
		$response = $this->post( '/client/contract', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['clients'] ) && is_array( $response['clients'] ) ) {
			return $response['clients'];
		}

		return array();
	}

	/**
	 * POST /services - services available on a date.
	 *
	 * @param string $date Date in Y-m-d.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_services( $date = '' ) {
		if ( '' === $date ) {
			$date = wp_date( 'Y-m-d' );
		}

		$response = $this->post( '/services', array( 'date' => $date ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['services'] ) && is_array( $response['services'] ) ) {
			return $response['services'];
		}

		return array();
	}

	/**
	 * POST /calculate - rate calculation.
	 *
	 * @param array<string, mixed> $payload Calculation payload (sender / recipient / service / content / payment).
	 * @return array<string, mixed>|WP_Error
	 */
	public function calculate( $payload ) {
		return $this->post( '/calculate', $payload );
	}

	/**
	 * POST /location/site - find Bulgarian sites (cities/villages) by name and/or post code.
	 *
	 * @param string $name      Site name.
	 * @param string $post_code Post code.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function find_sites( $name, $post_code = '' ) {
		$payload = array( 'countryId' => 100 );

		if ( '' !== $name ) {
			$payload['name'] = $name;
		}

		if ( '' !== $post_code ) {
			$payload['postCode'] = $post_code;
		}

		$response = $this->post( '/location/site', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['sites'] ) && is_array( $response['sites'] ) ? $response['sites'] : array();
	}

	/**
	 * POST /shipment - create one shipment.
	 *
	 * @param array<string, mixed> $payload Shipment payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_shipment( $payload ) {
		return $this->post( '/shipment', $payload );
	}

	/**
	 * POST /shipment/info - read shipment by ID.
	 *
	 * @param string|int $shipment_id Shipment ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_shipment_info( $shipment_id ) {
		return $this->post(
			'/shipment/info',
			array(
				'shipmentIds' => array( (string) $shipment_id ),
			)
		);
	}

	/**
	 * POST /shipment/cancel - cancel one shipment.
	 *
	 * Must be POST: WordPress sends DELETE data as a query string, so a JSON
	 * body never reached Speedy and the waybill stayed in MySpeedy.
	 *
	 * @param string|int $shipment_id Shipment ID.
	 * @param string     $comment     Cancellation reason.
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel_shipment( $shipment_id, $comment = '' ) {
		$comment = sanitize_text_field( $comment );

		return $this->post(
			'/shipment/cancel',
			array(
				'shipmentId' => (string) $shipment_id,
				'comment'    => '' !== $comment ? $comment : 'Отказана от търговеца',
			)
		);
	}

	/**
	 * POST /print - render shipment label.
	 *
	 * Returns a PDF binary string (or ZPL text).
	 *
	 * @param string|int $shipment_id Shipment ID.
	 * @param string     $paper_size  A4|A6|A4_4xA6.
	 * @return string|WP_Error
	 */
	public function print_label( $shipment_id, $paper_size = 'A6' ) {
		return $this->request_raw(
			'POST',
			'/print',
			array(
				'paperSize' => $this->normalize_paper_size( $paper_size ),
				'parcels'   => array(
					array( 'parcel' => array( 'id' => (string) $shipment_id ) ),
				),
			)
		);
	}

	/**
	 * POST /print/voucher - render voucher / collection note (пътен лист).
	 *
	 * @param array<int, string|int> $shipment_ids Shipment IDs.
	 * @return string|WP_Error
	 */
	public function print_voucher( $shipment_ids ) {
		$shipment_ids = array_filter( array_map( 'strval', (array) $shipment_ids ) );

		if ( empty( $shipment_ids ) ) {
			return new WP_Error( 'speedy_voucher_no_ids', 'Не са подадени товарителници за пътен лист.' );
		}

		return $this->request_raw(
			'POST',
			'/print/voucher',
			array(
				'shipmentIds' => array_values( $shipment_ids ),
			)
		);
	}

	/**
	 * POST /location/office - search Speedy offices.
	 *
	 * @param array<string, mixed> $filters Optional filters (countryId, siteId, name, type).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function find_offices( $filters = array() ) {
		$payload = wp_parse_args(
			$filters,
			array(
				'countryId' => 100,
			)
		);

		$response = $this->post( '/location/office', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['offices'] ) && is_array( $response['offices'] ) ) {
			return $response['offices'];
		}

		return array();
	}

	/**
	 * POST /pickup - request a courier visit.
	 *
	 * @param array<string, mixed> $payload Pickup payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function request_pickup( $payload ) {
		return $this->post( '/pickup', $payload );
	}

	/**
	 * POST /pickup/terms - cutoff times for a pickup date.
	 *
	 * @param string $date Y-m-d.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_pickup_terms( $date = '' ) {
		if ( '' === $date ) {
			$date = wp_date( 'Y-m-d' );
		}

		return $this->post( '/pickup/terms', array( 'date' => $date ) );
	}

	/**
	 * Convenience wrapper: request own client ID + record + contract.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function fetch_profile() {
		$client_id = $this->get_own_client_id();

		if ( is_wp_error( $client_id ) ) {
			return $client_id;
		}

		$client = $this->get_client( $client_id );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$contract = $this->get_contract_clients();

		if ( is_wp_error( $contract ) ) {
			$contract = array();
		}

		return array(
			'client_id'        => $client_id,
			'client'           => $client,
			'contract_clients' => $contract,
		);
	}

	/**
	 * @param string $size Raw paper size.
	 */
	private function normalize_paper_size( $size ) {
		$size      = strtoupper( (string) $size );
		$supported = array( 'A4', 'A6', 'A4_4XA6' );

		if ( 'A4_4XA6' === $size ) {
			return 'A4_4xA6';
		}

		return in_array( $size, $supported, true ) ? $size : 'A6';
	}

	/**
	 * @param string               $endpoint Endpoint path.
	 * @param array<string, mixed> $payload  Payload to merge with credentials.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( $endpoint, $payload ) {
		return $this->request( 'POST', $endpoint, $payload );
	}

	/**
	 * Run a JSON request and decode the response body.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $endpoint Endpoint path.
	 * @param array<string, mixed> $payload  Payload to merge with credentials.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( $method, $endpoint, $payload ) {
		$response = $this->raw_http_request( $method, $endpoint, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body         = wp_remote_retrieve_body( $response );
		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( '' === trim( (string) $body ) ) {
			if ( $status_code >= 200 && $status_code < 300 ) {
				return array();
			}

			return new WP_Error(
				'speedy_empty_response',
				sprintf( 'Speedy върна празен отговор (HTTP %d).', $status_code )
			);
		}

		if ( false === strpos( $content_type, 'json' ) ) {
			return new WP_Error(
				'speedy_non_json_response',
				sprintf( 'Очакван JSON, получен %s.', $content_type )
			);
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'speedy_invalid_json',
				sprintf( 'Speedy върна невалиден JSON: %s', json_last_error_msg() )
			);
		}

		if ( ! is_array( $decoded ) ) {
			$decoded = array( 'value' => $decoded );
		}

		if ( ! empty( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'Грешка от Speedy.';
			$code    = isset( $decoded['error']['id'] ) ? (string) $decoded['error']['id'] : 'speedy_error';

			return new WP_Error( $code ? 'speedy_' . $code : 'speedy_error', $message, $decoded['error'] );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'speedy_http_error',
				sprintf( 'Speedy върна HTTP %d.', $status_code ),
				$decoded
			);
		}

		return $decoded;
	}

	/**
	 * Run a request and return the raw body (binary). Used for PDF endpoints.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $endpoint Endpoint.
	 * @param array<string, mixed> $payload  Payload.
	 * @return string|WP_Error
	 */
	private function request_raw( $method, $endpoint, $payload ) {
		$response = $this->raw_http_request( $method, $endpoint, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$body         = wp_remote_retrieve_body( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$decoded = json_decode( $body, true );

			if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
				return new WP_Error( 'speedy_print_error', (string) $decoded['error']['message'], $decoded['error'] );
			}

			return new WP_Error( 'speedy_print_http_error', sprintf( 'Speedy върна HTTP %d.', $status_code ) );
		}

		if ( false !== strpos( $content_type, 'json' ) ) {
			$decoded = json_decode( $body, true );

			if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
				return new WP_Error( 'speedy_print_error', (string) $decoded['error']['message'], $decoded['error'] );
			}

			if ( is_array( $decoded ) && ! empty( $decoded['data'] ) ) {
				$binary = base64_decode( (string) $decoded['data'], true );

				if ( false !== $binary ) {
					return $binary;
				}
			}

			return new WP_Error( 'speedy_print_unexpected_json', 'Speedy върна неочакван JSON отговор за принт.' );
		}

		return (string) $body;
	}

	/**
	 * Low-level HTTP call with credentials merged in.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $endpoint Endpoint path.
	 * @param array<string, mixed> $payload  Payload.
	 * @return array|WP_Error
	 */
	private function raw_http_request( $method, $endpoint, $payload ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'speedy_no_credentials', 'Не са въведени потребител и парола за Speedy API.' );
		}

		$body = $this->build_request_body( $payload );
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json, application/pdf',
			),
			'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
		);

		return wp_remote_request( self::BASE_URL . $endpoint, $args );
	}

	/**
	 * Merge credentials into the request payload.
	 *
	 * @param array<string, mixed> $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function build_request_body( $payload ) {
		$body = array(
			'userName' => (string) $this->credentials['userName'],
			'password' => (string) $this->credentials['password'],
			'language' => $this->credentials['language'] ? (string) $this->credentials['language'] : 'BG',
		);

		if ( ! empty( $this->credentials['clientSystemId'] ) ) {
			$body['clientSystemId'] = (int) $this->credentials['clientSystemId'];
		}

		if ( is_array( $payload ) ) {
			$body = array_merge( $body, $payload );
		}

		return $body;
	}
}
