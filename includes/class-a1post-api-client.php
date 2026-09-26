<?php
/**
 * A1POST API client.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A1post_Api_Client {

	const DEFAULT_BASE_URL = 'https://api.a1post.bg/';
	const REQUEST_TIMEOUT  = 45;

	private $username;
	private $password;
	private $base_url;

	public function __construct( $settings = array() ) {
		$settings = wp_parse_args(
			(array) $settings,
			array(
				'account_user' => '',
				'account_pass' => '',
				'api_url'      => '',
			)
		);

		$this->username = (string) $settings['account_user'];
		$this->password = (string) $settings['account_pass'];
		$this->base_url = '' !== (string) $settings['api_url'] ? trailingslashit( (string) $settings['api_url'] ) : self::DEFAULT_BASE_URL;
	}

	public function is_configured() {
		return '' !== $this->username && '' !== $this->password;
	}

	/**
	 * Create an A1POST label.
	 *
	 * @param array<string, mixed> $fields API fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_label( $fields ) {
		$response = $this->request( 'POST', $this->base_url, $fields );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$track = $this->extract_tracking_number( $response );

		if ( '' === $track ) {
			return new WP_Error( 'a1post_no_tracking_number', 'A1POST did not return a tracking number.', $response );
		}

		$response['tracking_number'] = $track;

		return $response;
	}

	/**
	 * Download a printed label.
	 *
	 * @param string $tracking_number Tracking number.
	 * @param string $label_format    pdf_a4|pdf_a6|zpl.
	 * @return string|WP_Error
	 */
	public function print_label( $tracking_number, $label_format = 'pdf_a4' ) {
		$url = add_query_arg(
			array(
				'track' => (string) $tracking_number,
				'lbl'   => $this->normalize_label_format( $label_format ),
			),
			$this->base_url . 'print.php'
		);

		$response = $this->raw_request( 'GET', $url, array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'a1post_print_http_error', sprintf( 'A1POST returned HTTP %d while printing the label.', $status ), $body );
		}

		if ( '' === $body ) {
			return new WP_Error( 'a1post_empty_label', 'A1POST returned an empty label.' );
		}

		return $body;
	}

	/**
	 * Delete one label.
	 *
	 * @param string $tracking_number Tracking number.
	 * @return true|WP_Error
	 */
	public function delete_label( $tracking_number ) {
		$response = $this->request(
			'DELETE',
			$this->base_url,
			array(
				'trk' => (string) $tracking_number,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Calculate A1POST price.
	 *
	 * @param string $iso      ISO-2 country code.
	 * @param int    $weight   Weight in grams.
	 * @param string $postcode Postal code.
	 * @param array  $packages Optional package dimensions.
	 * @return array<string, mixed>|WP_Error
	 */
	public function calculate_price( $iso, $weight, $postcode = '', $packages = array() ) {
		$query = array(
			'iso'       => strtoupper( (string) $iso ),
			'weight'    => max( 1, (int) $weight ),
			'post_code' => (string) $postcode,
		);

		foreach ( (array) $packages as $index => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			foreach ( array( 'size1', 'size2', 'size3', 'wgh' ) as $key ) {
				if ( isset( $package[ $key ] ) && '' !== (string) $package[ $key ] ) {
					$query[ sprintf( 'pkg[%d][%s]', (int) $index, $key ) ] = $package[ $key ];
				}
			}
		}

		return $this->request( 'GET', add_query_arg( $query, $this->base_url . 'calc.php' ), array() );
	}

	private function request( $method, $url, $fields ) {
		$response = $this->raw_request( $method, $url, $fields );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$decoded      = null;

		if ( false !== strpos( $content_type, 'json' ) || $this->looks_like_json( $body ) ) {
			$decoded = json_decode( $body, true );
		}

		if ( is_array( $decoded ) ) {
			if ( ! empty( $decoded['error'] ) ) {
				return new WP_Error( 'a1post_api_error', $this->stringify_error( $decoded['error'] ), $decoded );
			}

			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error( 'a1post_http_error', sprintf( 'A1POST returned HTTP %d.', $status ), $decoded );
			}

			return $decoded;
		}

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'a1post_http_error', sprintf( 'A1POST returned HTTP %d: %s', $status, wp_strip_all_tags( $body ) ), $body );
		}

		return array(
			'raw' => trim( $body ),
		);
	}

	private function raw_request( $method, $url, $fields ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'a1post_no_credentials', 'A1POST API username and password are missing.' );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
				'Accept'        => 'application/json, application/pdf, text/plain, */*',
			),
		);

		if ( in_array( strtoupper( $method ), array( 'POST', 'DELETE' ), true ) ) {
			$args['body'] = (array) $fields;
		}

		return wp_remote_request( esc_url_raw( $url ), $args );
	}

	private function extract_tracking_number( $response ) {
		$candidates = array( 'tracking_number', 'track', 'tracking', 'trk', 'label', 'barcode', 'id' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $response[ $key ] ) && is_scalar( $response[ $key ] ) ) {
				return sanitize_text_field( (string) $response[ $key ] );
			}
		}

		if ( ! empty( $response['raw'] ) && preg_match( '/[A-Z0-9]{8,}/i', (string) $response['raw'], $match ) ) {
			return sanitize_text_field( $match[0] );
		}

		return '';
	}

	private function normalize_label_format( $format ) {
		$format = strtolower( (string) $format );

		return in_array( $format, array( 'pdf_a4', 'pdf_a6', 'zpl' ), true ) ? $format : 'pdf_a4';
	}

	private function looks_like_json( $body ) {
		$body = trim( (string) $body );

		return '' !== $body && in_array( substr( $body, 0, 1 ), array( '{', '[' ), true );
	}

	private function stringify_error( $error ) {
		if ( is_scalar( $error ) ) {
			return (string) $error;
		}

		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			return (string) $error['message'];
		}

		return 'A1POST returned an API error.';
	}
}
