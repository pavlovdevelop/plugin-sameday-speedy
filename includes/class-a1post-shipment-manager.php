<?php
/**
 * A1POST shipment workflow.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A1post_Shipment_Manager {

	const META_TRACKING       = '_a1post_tracking_number';
	const META_CREATED_AT     = '_a1post_label_created_at';
	const META_REQUEST        = '_a1post_label_request';
	const META_RESPONSE       = '_a1post_label_response';
	const META_LAST_ERROR     = '_a1post_label_last_error';
	const META_LABEL_FORMAT   = '_a1post_label_format';
	const META_SERVICE        = '_a1post_service';
	const META_WEIGHT_GRAMS   = '_a1post_weight_grams';
	const META_CONTENTS       = '_a1post_contents';

	private $client;

	public function __construct( $client = null ) {
		$this->client = $client instanceof A1post_Api_Client ? $client : new A1post_Api_Client( A1post_Tariff::get_settings() );
	}

	public static function read_order_meta( $order, $key ) {
		if ( class_exists( 'Speedy_Shipment_Manager' ) ) {
			return Speedy_Shipment_Manager::read_order_meta( $order, $key );
		}

		$value = $order->get_meta( $key, true );

		if ( '' !== $value && null !== $value && false !== $value ) {
			return $value;
		}

		return get_post_meta( $order->get_id(), $key, true );
	}

	public function order_uses_a1post( $order ) {
		return 'a1post' === (string) self::read_order_meta( $order, '_sameday_delivery_provider' )
			|| 'a1post_international' === (string) self::read_order_meta( $order, '_sameday_delivery_service' );
	}

	public function generate_for_order( $order, $overrides = array() ) {
		if ( ! $this->client->is_configured() ) {
			return new WP_Error( 'a1post_not_configured', 'A1POST API credentials are not configured.' );
		}

		if ( ! $this->order_uses_a1post( $order ) ) {
			return new WP_Error( 'a1post_wrong_provider', 'This order is not using A1POST delivery.' );
		}

		$existing = (string) $order->get_meta( self::META_TRACKING );

		if ( '' !== $existing ) {
			return new WP_Error( 'a1post_already_created', sprintf( 'A1POST label already exists: %s.', $existing ) );
		}

		$payload = $this->build_payload( $order, $overrides );

		if ( is_wp_error( $payload ) ) {
			$order->update_meta_data( self::META_LAST_ERROR, $payload->get_error_message() );
			$order->save_meta_data();

			return $payload;
		}

		$response = $this->client->create_label( $payload );

		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( self::META_LAST_ERROR, $response->get_error_message() );
			$order->save_meta_data();

			return $response;
		}

		$tracking = (string) $response['tracking_number'];

		$order->update_meta_data( self::META_TRACKING, $tracking );
		$order->update_meta_data( self::META_CREATED_AT, time() );
		$order->update_meta_data( self::META_REQUEST, $this->redact_payload( $payload ) );
		$order->update_meta_data( self::META_RESPONSE, $response );
		$order->update_meta_data( self::META_LAST_ERROR, '' );
		$order->update_meta_data( self::META_LABEL_FORMAT, (string) $payload['lbl'] );
		$order->update_meta_data( self::META_SERVICE, (string) $payload['serv'] );
		$order->update_meta_data( self::META_WEIGHT_GRAMS, (string) $payload['weight'] );
		$order->update_meta_data( self::META_CONTENTS, (string) $payload['content'] );
		$order->save_meta_data();

		$order->add_order_note( sprintf( 'A1POST label %s was created successfully.', $tracking ) );

		return array(
			'tracking_number' => $tracking,
			'response'        => $response,
		);
	}

	public function print_label( $order, $label_format = '' ) {
		$tracking = (string) $order->get_meta( self::META_TRACKING );

		if ( '' === $tracking ) {
			return new WP_Error( 'a1post_no_label', 'This order does not have an A1POST label yet.' );
		}

		if ( '' === $label_format ) {
			$label_format = (string) A1post_Tariff::get( 'label_format', 'pdf_a4' );
		}

		return $this->client->print_label( $tracking, $label_format );
	}

	public function delete_label( $order ) {
		$tracking = (string) $order->get_meta( self::META_TRACKING );

		if ( '' === $tracking ) {
			return new WP_Error( 'a1post_no_label', 'This order does not have an A1POST label to delete.' );
		}

		$result = $this->client->delete_label( $tracking );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->delete_meta_data( self::META_TRACKING );
		$order->delete_meta_data( self::META_CREATED_AT );
		$order->delete_meta_data( self::META_REQUEST );
		$order->delete_meta_data( self::META_RESPONSE );
		$order->delete_meta_data( self::META_LABEL_FORMAT );
		$order->delete_meta_data( self::META_SERVICE );
		$order->delete_meta_data( self::META_WEIGHT_GRAMS );
		$order->delete_meta_data( self::META_CONTENTS );
		$order->update_meta_data( self::META_LAST_ERROR, '' );
		$order->save_meta_data();

		$order->add_order_note( sprintf( 'A1POST label %s was deleted.', $tracking ) );

		return true;
	}

	private function build_payload( $order, $overrides ) {
		$country = $this->get_destination_country( $order );

		if ( '' === $country || 'BG' === $country ) {
			return new WP_Error( 'a1post_invalid_country', 'A1POST is available only for delivery outside Bulgaria.' );
		}

		$name = $this->get_recipient_name( $order, $overrides );

		if ( '' === $name ) {
			return new WP_Error( 'a1post_missing_name', 'Recipient name is missing.' );
		}

		$address_1 = $this->pick( $overrides, 'addr1', $this->get_a1post_or_order_field( $order, 'address_1' ) );
		$city      = $this->pick( $overrides, 'city', $this->get_a1post_or_order_field( $order, 'city' ) );
		$postcode  = $this->pick( $overrides, 'zip', $this->get_a1post_or_order_field( $order, 'postcode' ) );
		$phone     = $this->pick( $overrides, 'tel', $this->get_a1post_or_order_field( $order, 'phone' ) );
		$email     = $this->pick( $overrides, 'email', $this->get_a1post_or_order_field( $order, 'email' ) );

		if ( '' === $address_1 || '' === $city || '' === $postcode || '' === $phone || '' === $email ) {
			return new WP_Error( 'a1post_missing_address', 'Recipient address, city, postal code, phone and email are required for A1POST.' );
		}

		$weight_grams = isset( $overrides['weight'] ) ? (int) $overrides['weight'] : $this->calculate_order_weight_grams( $order );
		$content      = $this->pick( $overrides, 'content', $this->compose_contents( $order ) );
		$value        = isset( $overrides['value'] ) && '' !== (string) $overrides['value'] ? (float) $overrides['value'] : (float) $order->get_subtotal();

		$payload = array(
			'name'    => mb_substr( $name, 0, 40 ),
			'addr1'   => $address_1,
			'addr2'   => $this->pick( $overrides, 'addr2', $this->get_a1post_or_order_field( $order, 'address_2' ) ),
			'addr3'   => isset( $overrides['addr3'] ) ? sanitize_text_field( (string) $overrides['addr3'] ) : '',
			'state'   => $this->pick( $overrides, 'state', $this->get_a1post_or_order_field( $order, 'state' ) ),
			'city'    => $city,
			'zip'     => $postcode,
			'iso'     => $country,
			'tel'     => $phone,
			'tel2'    => isset( $overrides['tel2'] ) ? sanitize_text_field( (string) $overrides['tel2'] ) : '',
			'email'   => $email,
			'content' => mb_substr( $content, 0, 120 ),
			'weight'  => max( 1, $weight_grams ),
			'ioss'    => isset( $overrides['ioss'] ) ? sanitize_text_field( (string) $overrides['ioss'] ) : (string) A1post_Tariff::get( 'ioss', '' ),
			'HS'      => isset( $overrides['hs'] ) ? sanitize_text_field( (string) $overrides['hs'] ) : (string) A1post_Tariff::get( 'default_hs_code', '' ),
			'value'   => max( 0.01, $value ),
			'notes'   => isset( $overrides['notes'] ) ? sanitize_text_field( (string) $overrides['notes'] ) : $this->get_a1post_or_order_field( $order, 'notes' ),
			'lbl'     => isset( $overrides['label_format'] ) ? sanitize_text_field( (string) $overrides['label_format'] ) : (string) A1post_Tariff::get( 'label_format', 'pdf_a4' ),
			'serv'    => isset( $overrides['service'] ) ? sanitize_text_field( (string) $overrides['service'] ) : (string) A1post_Tariff::get( 'service_code', 'L' ),
		);

		return apply_filters( 'sameday_a1post_label_payload', $payload, $order, $overrides );
	}

	private function get_destination_country( $order ) {
		$country = (string) self::read_order_meta( $order, '_sameday_a1post_country' );

		if ( '' === $country ) {
			$country = (string) $order->get_shipping_country();
		}

		if ( '' === $country ) {
			$country = (string) $order->get_billing_country();
		}

		return strtoupper( $country );
	}

	private function get_recipient_name( $order, $overrides ) {
		if ( ! empty( $overrides['name'] ) ) {
			return sanitize_text_field( (string) $overrides['name'] );
		}

		$a1post_name = (string) self::read_order_meta( $order, '_sameday_a1post_name' );

		if ( '' !== $a1post_name ) {
			return $a1post_name;
		}

		$name = trim( (string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name() );

		if ( '' === $name ) {
			$name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
		}

		if ( '' === $name ) {
			$name = (string) $order->get_billing_company();
		}

		return $name;
	}

	private function get_a1post_or_order_field( $order, $field ) {
		$a1post_meta = array(
			'address_1' => '_sameday_a1post_address_1',
			'address_2' => '_sameday_a1post_address_2',
			'city'      => '_sameday_a1post_city',
			'state'     => '_sameday_a1post_state',
			'postcode'  => '_sameday_a1post_postcode',
			'phone'     => '_sameday_a1post_phone',
			'email'     => '_sameday_a1post_email',
			'notes'     => '_sameday_a1post_notes',
		);

		if ( isset( $a1post_meta[ $field ] ) ) {
			$value = (string) self::read_order_meta( $order, $a1post_meta[ $field ] );

			if ( '' !== $value ) {
				return $value;
			}
		}

		if ( 'phone' === $field ) {
			return (string) $order->get_billing_phone();
		}

		if ( 'email' === $field ) {
			return (string) $order->get_billing_email();
		}

		if ( 'notes' === $field ) {
			return trim( (string) $order->get_customer_note() );
		}

		return $this->get_shipping_or_billing( $order, $field );
	}

	private function get_shipping_or_billing( $order, $field ) {
		$shipping_method = 'get_shipping_' . $field;
		$billing_method  = 'get_billing_' . $field;
		$value           = method_exists( $order, $shipping_method ) ? (string) $order->{$shipping_method}() : '';

		if ( '' === $value && method_exists( $order, $billing_method ) ) {
			$value = (string) $order->{$billing_method}();
		}

		return $value;
	}

	private function pick( $overrides, $key, $fallback ) {
		return isset( $overrides[ $key ] ) && '' !== (string) $overrides[ $key ]
			? sanitize_text_field( (string) $overrides[ $key ] )
			: sanitize_text_field( (string) $fallback );
	}

	private function calculate_order_weight_grams( $order ) {
		$weight = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;

			if ( ! $product || ! method_exists( $product, 'get_weight' ) ) {
				continue;
			}

			$item_weight = (float) wc_get_weight( (float) $product->get_weight(), 'kg' );
			$weight     += $item_weight * max( 1, (int) $item->get_quantity() );
		}

		if ( $weight <= 0 ) {
			$weight = (float) A1post_Tariff::get( 'default_weight_kg', 1 );
		}

		return max( 1, (int) ceil( $weight * 1000 ) );
	}

	private function compose_contents( $order ) {
		$names = array();

		foreach ( $order->get_items() as $item ) {
			if ( method_exists( $item, 'get_name' ) ) {
				$names[] = $item->get_name();
			}
		}

		$content = implode( ', ', array_filter( $names ) );

		return '' !== $content ? $content : (string) A1post_Tariff::get( 'default_contents', 'Goods' );
	}

	private function redact_payload( $payload ) {
		unset( $payload['tel'], $payload['tel2'], $payload['email'] );

		return $payload;
	}
}
