<?php
/**
 * High-level Speedy shipment workflow.
 *
 * Builds the create-shipment payload from an order, persists waybill data
 * back to order meta, and exposes simple wrappers used by the admin AJAX
 * endpoints.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Speedy_Shipment_Manager {

	const META_SHIPMENT_ID  = '_speedy_shipment_id';
	const META_PARCEL_DATA  = '_speedy_shipment_parcels';
	const META_PRICE        = '_speedy_shipment_price';
	const META_CREATED_AT   = '_speedy_shipment_created_at';
	const META_PICKUP_DATE  = '_speedy_shipment_pickup_date';
	const META_REQUEST_DUMP = '_speedy_shipment_request';
	const META_LAST_ERROR   = '_speedy_shipment_last_error';

	/**
	 * @var Speedy_Api_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Speedy_Api_Client|null $client Optional pre-built client.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Speedy_Api_Client
			? $client
			: new Speedy_Api_Client( Speedy_Settings::get_credentials() );
	}

	/**
	 * Read one order meta value, falling back to legacy postmeta.
	 *
	 * The bundled checkout class still writes the delivery selection via
	 * `update_post_meta()`, which on HPOS-enabled stores ends up in
	 * `wp_postmeta` while `$order->get_meta()` reads the orders table. We
	 * try the modern store first and fall through to postmeta when needed.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 * @return mixed
	 */
	public static function read_order_meta( $order, $key ) {
		$value = $order->get_meta( $key, true );

		if ( '' !== $value && null !== $value && false !== $value ) {
			return $value;
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;

		if ( $order_id <= 0 ) {
			return $value;
		}

		return get_post_meta( $order_id, $key, true );
	}

	/**
	 * Whether the order is targeting Speedy.
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_uses_speedy( $order ) {
		$provider = (string) self::read_order_meta( $order, '_sameday_delivery_provider' );

		return 'speedy' === $provider;
	}

	/**
	 * Generate one shipment for the order.
	 *
	 * @param WC_Order             $order    Order.
	 * @param array<string, mixed> $overrides Optional overrides for parcels/weight/cod/etc.
	 * @return array<string, mixed>|WP_Error
	 */
	public function generate_for_order( $order, $overrides = array() ) {
		if ( ! $this->client->is_configured() ) {
			return new WP_Error( 'speedy_not_configured', 'Speedy API не е настроен (потребител и парола липсват).' );
		}

		if ( ! $this->order_uses_speedy( $order ) ) {
			return new WP_Error( 'speedy_wrong_provider', 'Поръчката не е със Speedy куриер.' );
		}

		$existing = (string) $order->get_meta( self::META_SHIPMENT_ID );

		if ( '' !== $existing ) {
			return new WP_Error( 'speedy_already_created', sprintf( 'За тази поръчка вече има генерирана товарителница №%s.', $existing ) );
		}

		$payload = $this->build_payload( $order, $overrides );

		if ( is_wp_error( $payload ) ) {
			$order->update_meta_data( self::META_LAST_ERROR, $payload->get_error_message() );
			$order->save_meta_data();

			return $payload;
		}

		$response = $this->client->create_shipment( $payload );

		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( self::META_LAST_ERROR, $response->get_error_message() );
			$order->save_meta_data();

			return $response;
		}

		$shipment_id = isset( $response['id'] ) ? (string) $response['id'] : '';

		if ( '' === $shipment_id ) {
			$order->update_meta_data( self::META_LAST_ERROR, 'Speedy не върна товарителница.' );
			$order->save_meta_data();

			return new WP_Error( 'speedy_no_shipment_id', 'Speedy не върна товарителница.' );
		}

		$parcels = isset( $response['parcels'] ) && is_array( $response['parcels'] ) ? $response['parcels'] : array();
		$price   = isset( $response['price'] ) && is_array( $response['price'] ) ? $response['price'] : array();

		$order->update_meta_data( self::META_SHIPMENT_ID, $shipment_id );
		$order->update_meta_data( self::META_PARCEL_DATA, $parcels );
		$order->update_meta_data( self::META_PRICE, $price );
		$order->update_meta_data( self::META_CREATED_AT, time() );

		$response_pickup_date = ! empty( $response['pickupDate'] )
			? sanitize_text_field( (string) $response['pickupDate'] )
			: ( isset( $payload['service']['pickupDate'] ) ? sanitize_text_field( (string) $payload['service']['pickupDate'] ) : '' );

		if ( '' !== $response_pickup_date ) {
			$order->update_meta_data( self::META_PICKUP_DATE, $response_pickup_date );
		}

		$order->update_meta_data( self::META_LAST_ERROR, '' );
		$order->update_meta_data( self::META_REQUEST_DUMP, $payload );
		$order->save_meta_data();

		$order->add_order_note(
			sprintf( 'Speedy товарителница №%s беше генерирана успешно%s.', $shipment_id, '' !== $response_pickup_date ? ' с дата на взимане ' . $response_pickup_date : '' )
		);

		return array(
			'shipment_id' => $shipment_id,
			'parcels'     => $parcels,
			'price'       => $price,
			'response'    => $response,
		);
	}

	/**
	 * Print the waybill PDF for the order's shipment.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $paper_size A4|A6|A4_4xA6.
	 * @return string|WP_Error PDF binary.
	 */
	public function print_waybill( $order, $paper_size = '' ) {
		$shipment_id = (string) $order->get_meta( self::META_SHIPMENT_ID );

		if ( '' === $shipment_id ) {
			return new WP_Error( 'speedy_no_shipment', 'Поръчката няма генерирана товарителница.' );
		}

		if ( '' === $paper_size ) {
			$paper_size = (string) Speedy_Settings::get( 'paper_size', 'A6' );
		}

		return $this->client->print_label( $shipment_id, $paper_size );
	}

	/**
	 * Print the courier voucher (пътен лист) for the order's shipment.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error PDF binary.
	 */
	public function print_voucher( $order ) {
		$shipment_id = (string) $order->get_meta( self::META_SHIPMENT_ID );

		if ( '' === $shipment_id ) {
			return new WP_Error( 'speedy_no_shipment', 'Поръчката няма генерирана товарителница.' );
		}

		return $this->client->print_voucher( array( $shipment_id ) );
	}

	/**
	 * Cancel the order's shipment.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $comment Cancellation reason.
	 * @return true|WP_Error
	 */
	public function cancel( $order, $comment = 'Отказана от търговеца' ) {
		$shipment_id = (string) $order->get_meta( self::META_SHIPMENT_ID );

		if ( '' === $shipment_id ) {
			return new WP_Error( 'speedy_no_shipment', 'Поръчката няма товарителница за изтриване.' );
		}

		$response = $this->client->cancel_shipment( $shipment_id, $comment );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$order->delete_meta_data( self::META_SHIPMENT_ID );
		$order->delete_meta_data( self::META_PARCEL_DATA );
		$order->delete_meta_data( self::META_PRICE );
		$order->delete_meta_data( self::META_CREATED_AT );
		$order->delete_meta_data( self::META_PICKUP_DATE );
		$order->delete_meta_data( self::META_REQUEST_DUMP );
		$order->update_meta_data( self::META_LAST_ERROR, '' );
		$order->save_meta_data();

		$order->add_order_note(
			sprintf( 'Speedy товарителница №%s беше изтрита. Причина: %s', $shipment_id, $comment )
		);

		return true;
	}

	/**
	 * Request a courier pickup for the order's shipment.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $date    Y-m-d.
	 * @param int      $hour    Hour (8-18).
	 * @return array<string, mixed>|WP_Error
	 */
	public function request_pickup( $order, $date = '', $hour = 0 ) {
		$shipment_id = (string) $order->get_meta( self::META_SHIPMENT_ID );

		if ( '' === $shipment_id ) {
			return new WP_Error( 'speedy_no_shipment', 'Поръчката няма генерирана товарителница.' );
		}

		$client_id = $this->resolve_sender_client_id();

		if ( $client_id <= 0 ) {
			return new WP_Error( 'speedy_no_sender_client', 'Speedy clientId на изпращача не е известен.' );
		}

		if ( '' === $date ) {
			$date = $this->next_business_day();
		}

		$payload = array(
			'senderId'      => $client_id,
			'shipmentIds'   => array( $shipment_id ),
			'pickupDate'    => $date,
		);

		if ( $hour > 0 ) {
			$payload['pickupTimeFrom'] = sprintf( '%02d:00', max( 8, min( 18, (int) $hour ) ) );
			$payload['pickupTimeTo']   = sprintf( '%02d:00', max( 9, min( 19, (int) $hour + 1 ) ) );
		}

		$response = $this->client->request_pickup( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$order->add_order_note(
			sprintf( 'Заявен куриер за товарителница №%s на %s.', $shipment_id, $date )
		);

		return $response;
	}

	/**
	 * Build the create-shipment payload from order + overrides.
	 *
	 * @param WC_Order             $order    Order.
	 * @param array<string, mixed> $overrides Optional overrides from the meta box form.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_payload( $order, $overrides = array() ) {
		$service    = (string) self::read_order_meta( $order, '_sameday_delivery_service' );
		$service_id = $this->resolve_service_id( $service, $overrides );

		if ( $service_id <= 0 ) {
			return new WP_Error( 'speedy_no_service_id', 'Не е настроен service ID за избрания тип Speedy услуга.' );
		}

		$sender = $this->build_sender( $overrides );

		if ( is_wp_error( $sender ) ) {
			return $sender;
		}

		$parcels_count = isset( $overrides['parcels'] ) ? max( 1, (int) $overrides['parcels'] ) : (int) Speedy_Settings::get( 'default_parcels', 1 );
		$weight        = isset( $overrides['weight'] ) ? max( 0.1, (float) $overrides['weight'] ) : (float) Speedy_Settings::get( 'default_weight', 1.0 );
		$contents      = isset( $overrides['contents'] ) && '' !== $overrides['contents'] ? sanitize_text_field( (string) $overrides['contents'] ) : (string) Speedy_Settings::get( 'default_contents', 'Стоки' );
		$pack          = isset( $overrides['pack'] ) && '' !== $overrides['pack'] ? sanitize_text_field( (string) $overrides['pack'] ) : (string) Speedy_Settings::get( 'default_pack', 'КУТИЯ' );
		$length        = isset( $overrides['length'] ) ? max( 0, (int) $overrides['length'] ) : (int) Speedy_Settings::get( 'default_length', 0 );
		$width         = isset( $overrides['width'] ) ? max( 0, (int) $overrides['width'] ) : (int) Speedy_Settings::get( 'default_width', 0 );
		$height        = isset( $overrides['height'] ) ? max( 0, (int) $overrides['height'] ) : (int) Speedy_Settings::get( 'default_height', 0 );
		$note          = isset( $overrides['note'] ) ? sanitize_text_field( (string) $overrides['note'] ) : trim( (string) $order->get_customer_note() );

		$cod_on = isset( $overrides['cod_on'] )
			? ( 'yes' === $overrides['cod_on'] )
			: ( 'yes' === Speedy_Settings::get( 'cod_on', 'yes' ) );

		$cod_amount = isset( $overrides['cod_amount'] )
			? max( 0.0, (float) $overrides['cod_amount'] )
			: (float) $order->get_total();

		$declared_value_on = isset( $overrides['declared_value_on'] )
			? ( 'yes' === $overrides['declared_value_on'] )
			: ( 'yes' === Speedy_Settings::get( 'declared_value_on', 'no' ) );

		$declared_value_amount = isset( $overrides['declared_value_amount'] )
			? max( 0.0, (float) $overrides['declared_value_amount'] )
			: (float) $order->get_subtotal();

		$fragile = isset( $overrides['fragile'] )
			? ( 'yes' === $overrides['fragile'] )
			: ( 'yes' === Speedy_Settings::get( 'fragile_default', 'no' ) );

		$saturday = isset( $overrides['saturday'] )
			? ( 'yes' === $overrides['saturday'] )
			: ( 'yes' === Speedy_Settings::get( 'saturday_default', 'no' ) );

		$payer_courier = ! empty( $overrides['payer_courier'] )
			? strtoupper( (string) $overrides['payer_courier'] )
			: (string) Speedy_Settings::get( 'payer_courier', 'SENDER' );

		$payment = array(
			'courierServicePayer' => $payer_courier,
		);

		if ( $declared_value_on ) {
			$payment['declaredValuePayer'] = (string) Speedy_Settings::get( 'payer_declared', 'SENDER' );
		}

		$additional_services = array();

		if ( $cod_on && $cod_amount > 0 && $this->order_is_cod( $order ) ) {
			$additional_services['cod'] = array(
				'amount'       => round( $cod_amount, 2 ),
				'currencyCode' => $order->get_currency() ? $order->get_currency() : 'BGN',
				'processingType' => isset( $overrides['cod_type'] ) && 'opp' === $overrides['cod_type'] ? 'POSTAL_MONEY_TRANSFER' : 'CASH',
			);

			$payment['codPayer'] = (string) Speedy_Settings::get( 'payer_cod', 'RECIPIENT' );
		}

		if ( $declared_value_on && $declared_value_amount > 0 ) {
			$additional_services['declaredValue'] = array(
				'amount'  => round( $declared_value_amount, 2 ),
				'fragile' => $fragile,
			);
		} elseif ( $fragile ) {
			$additional_services['fragile'] = true;
		}

		if ( $saturday ) {
			$additional_services['saturdayDelivery'] = true;
		}

		// Return shipment / OPP support.
		$opp_on = isset( $overrides['opp_on'] )
			? ( 'yes' === $overrides['opp_on'] )
			: ( 'yes' === Speedy_Settings::get( 'opp_on', 'no' ) );

		if ( $opp_on ) {
			$return_service = isset( $overrides['return_service_id'] )
				? (int) $overrides['return_service_id']
				: (int) Speedy_Settings::get( 'return_service_opp', 0 );

			if ( $return_service > 0 ) {
				$additional_services['returnShipment'] = array(
					'serviceId'  => $return_service,
					'payer'      => (string) Speedy_Settings::get( 'return_payer_opp', 'RECIPIENT' ),
					'returnType' => 'WITH_OPP',
				);
			}
		}

		$recipient = $this->build_recipient( $order, $overrides );

		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}

		$pickup_date = ! empty( $overrides['pickup_date'] )
			? $this->sanitize_pickup_date( (string) $overrides['pickup_date'] )
			: $this->next_business_day();

		$service_block = array(
			'serviceId'  => $service_id,
			'pickupDate' => $pickup_date,
		);

		if ( ! empty( $additional_services ) ) {
			$service_block['additionalServices'] = $additional_services;
		}

		if ( 'speedy_office' === $service || 'speedy_aps' === $service ) {
			$office_id = (int) self::read_order_meta( $order, '_sameday_speedy_location_id' );

			if ( $office_id > 0 ) {
				$service_block['toBeCalled'] = false;
				$recipient['pickupOfficeId'] = $office_id;
			}
		}

		$content_block = array(
			'parcelsCount' => $parcels_count,
			'totalWeight'  => round( $weight, 3 ),
			'contents'     => $contents,
			'package'      => $pack,
		);

		// Per-parcel sizes (applied to all parcels evenly when overrides aren't per-parcel arrays).
		if ( $length > 0 && $width > 0 && $height > 0 ) {
			$parcels_payload = array();

			$per_parcel = isset( $overrides['parcel_rows'] ) && is_array( $overrides['parcel_rows'] )
				? $overrides['parcel_rows']
				: array();

			for ( $i = 0; $i < $parcels_count; $i++ ) {
				$row = isset( $per_parcel[ $i ] ) && is_array( $per_parcel[ $i ] ) ? $per_parcel[ $i ] : array();

				$parcels_payload[] = array(
					'seqNo'  => $i + 1,
					'weight' => isset( $row['weight'] ) ? max( 0.1, (float) $row['weight'] ) : round( $weight / max( 1, $parcels_count ), 3 ),
					'size'   => array(
						'depth'  => isset( $row['length'] ) ? (int) $row['length'] : $length,
						'width'  => isset( $row['width'] ) ? (int) $row['width'] : $width,
						'height' => isset( $row['height'] ) ? (int) $row['height'] : $height,
					),
				);
			}

			$content_block['parcels'] = $parcels_payload;
		}

		$payload = array(
			'sender'    => $sender,
			'recipient' => $recipient,
			'service'   => $service_block,
			'content'   => $content_block,
			'payment'   => $payment,
			'ref1'      => (string) $order->get_order_number(),
		);

		if ( '' !== $note ) {
			$payload['shipmentNote'] = mb_substr( $note, 0, 200 );
		}

		return apply_filters( 'sameday_speedy_shipment_payload', $payload, $order, $overrides );
	}

	/**
	 * Build the sender block based on settings + overrides.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_sender( $overrides ) {
		$sender_type = isset( $overrides['sender_type'] )
			? (string) $overrides['sender_type']
			: (string) Speedy_Settings::get( 'sender_type', 'address' );

		$client_id = isset( $overrides['sender_client_id'] ) && (int) $overrides['sender_client_id'] > 0
			? (int) $overrides['sender_client_id']
			: (int) Speedy_Settings::get( 'sender_client_id', 0 );

		if ( $client_id <= 0 ) {
			$client_id = $this->resolve_sender_client_id();
		}

		if ( $client_id <= 0 ) {
			return new WP_Error( 'speedy_no_sender_client', 'Не е избран адрес/клиент на изпращача. Презаредете профила и изберете адрес в настройките.' );
		}

		$sender = array(
			'clientId' => $client_id,
		);

		$contact_name = isset( $overrides['sender_name'] ) ? sanitize_text_field( (string) $overrides['sender_name'] ) : (string) Speedy_Settings::get( 'sender_name', '' );
		$phone        = isset( $overrides['sender_phone'] ) ? $this->normalize_phone( (string) $overrides['sender_phone'] ) : $this->normalize_phone( (string) Speedy_Settings::get( 'sender_phone', '' ) );
		$email        = isset( $overrides['sender_email'] ) ? sanitize_email( (string) $overrides['sender_email'] ) : (string) Speedy_Settings::get( 'sender_email', '' );

		if ( '' !== $contact_name ) {
			$sender['contactName'] = $contact_name;
		}

		if ( '' !== $phone ) {
			$sender['phone1'] = array( 'number' => $phone );
		}

		if ( '' !== $email ) {
			$sender['email'] = $email;
		}

		if ( 'office' === $sender_type ) {
			$office_id = isset( $overrides['sender_office_id'] ) && (int) $overrides['sender_office_id'] > 0
				? (int) $overrides['sender_office_id']
				: (int) Speedy_Settings::get( 'sender_office_id', 0 );

			if ( $office_id > 0 ) {
				$sender['dropoffOfficeId'] = $office_id;
			}
		}

		return $sender;
	}

	/**
	 * Resolve the Speedy service ID for a service code, honouring overrides.
	 *
	 * @param string               $service   Service code.
	 * @param array<string, mixed> $overrides Overrides.
	 * @return int
	 */
	private function resolve_service_id( $service, $overrides ) {
		if ( ! empty( $overrides['service_id'] ) ) {
			return (int) $overrides['service_id'];
		}

		switch ( $service ) {
			case 'speedy_office':
				return (int) Speedy_Settings::get( 'service_id_office' );

			case 'speedy_aps':
				return (int) Speedy_Settings::get( 'service_id_aps' );

			case 'speedy_door':
				return (int) Speedy_Settings::get( 'service_id_door' );
		}

		return 0;
	}

	/**
	 * Sender client ID from cached profile.
	 */
	private function resolve_sender_client_id() {
		$profile = Speedy_Settings::get_profile();

		return isset( $profile['client_id'] ) ? (int) $profile['client_id'] : 0;
	}

	/**
	 * Build the recipient block from an order.
	 *
	 * @param WC_Order             $order     Order.
	 * @param array<string, mixed> $overrides Form overrides from the meta box.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_recipient( $order, $overrides = array() ) {
		$first = trim( (string) $order->get_shipping_first_name() );
		$last  = trim( (string) $order->get_shipping_last_name() );

		if ( '' === $first && '' === $last ) {
			$first = trim( (string) $order->get_billing_first_name() );
			$last  = trim( (string) $order->get_billing_last_name() );
		}

		$contact_name = ! empty( $overrides['recipient_name'] )
			? sanitize_text_field( (string) $overrides['recipient_name'] )
			: trim( $first . ' ' . $last );

		$company = trim( (string) $order->get_billing_company() );

		$phone = ! empty( $overrides['recipient_phone'] )
			? $this->normalize_phone( (string) $overrides['recipient_phone'] )
			: $this->normalize_phone( (string) $order->get_billing_phone() );

		$email = ! empty( $overrides['recipient_email'] )
			? sanitize_email( (string) $overrides['recipient_email'] )
			: sanitize_email( (string) $order->get_billing_email() );

		if ( '' === $contact_name ) {
			$contact_name = $company !== '' ? $company : 'Получател';
		}

		$service = (string) self::read_order_meta( $order, '_sameday_delivery_service' );

		$address = array(
			'countryId' => 100, // Bulgaria.
		);

		if ( 'speedy_office' === $service || 'speedy_aps' === $service ) {
			$site_id = (int) self::read_order_meta( $order, '_sameday_speedy_city_id' );

			if ( $site_id <= 0 ) {
				return new WP_Error( 'speedy_no_site', 'Липсва избран Speedy град/офис в поръчката.' );
			}

			$address['siteId']      = $site_id;
			$address['addressNote'] = (string) self::read_order_meta( $order, '_sameday_speedy_city_label' );
		} elseif ( 'speedy_door' === $service ) {
			$door_city    = (string) self::read_order_meta( $order, '_sameday_speedy_door_city' );
			$door_address = (string) self::read_order_meta( $order, '_sameday_speedy_door_address' );

			if ( '' === $door_city || '' === $door_address ) {
				return new WP_Error( 'speedy_no_door_address', 'Липсват адрес и/или населено място за доставка със Speedy.' );
			}

			$address['siteName']    = $door_city;
			$address['addressNote'] = $door_address;
		} else {
			return new WP_Error( 'speedy_unsupported_service', 'Услугата на Speedy не се поддържа за тази поръчка.' );
		}

		$recipient = array(
			'clientName'    => $contact_name,
			'privatePerson' => '' === $company,
			'address'       => $address,
		);

		if ( '' !== $phone ) {
			$recipient['phone1'] = array( 'number' => $phone );
		}

		if ( '' !== $company ) {
			$recipient['contactName'] = $contact_name;
		}

		if ( '' !== $email ) {
			$recipient['email'] = $email;
		}

		return $recipient;
	}

	/**
	 * Detect a COD payment method on the order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function order_is_cod( $order ) {
		$method = strtolower( (string) $order->get_payment_method() );

		return false !== strpos( $method, 'cod' )
			|| in_array( $method, array( 'cheque', 'cheque_payment', 'naloji', 'nalozhen', 'nalozhen_platej' ), true );
	}

	/**
	 * Normalize a phone number into the format Speedy expects.
	 *
	 * Speedy validates Bulgarian phones strictly: 10-digit national
	 * format starting with 0 (e.g. 0878543378). Customers often enter
	 * the number with country prefix, spaces, dashes or parentheses,
	 * or skip the leading zero entirely.
	 *
	 * @param string $phone Raw phone.
	 */
	private function normalize_phone( $phone ) {
		$phone = trim( (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		$digits = preg_replace( '/[^0-9]/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		// "00..." → drop the international prefix.
		$digits = preg_replace( '/^00/', '', $digits );

		// "359..." → drop the BG country code (also handles original "+359 0…").
		$digits = preg_replace( '/^359/', '', $digits );

		// 9-digit mobile without leading 0 (e.g. "878543378") → "0878543378".
		if ( 9 === strlen( $digits ) ) {
			$digits = '0' . $digits;
		}

		return $digits;
	}

	/**
	 * Pick the next business-day pickup date in Y-m-d.
	 */
	public static function get_default_pickup_date() {
		$timestamp = current_time( 'timestamp' );
		$today_dow = (int) wp_date( 'N', $timestamp );

		if ( $today_dow >= 5 ) {
			$add_days = 8 - $today_dow;
		} else {
			$add_days = 0;
		}

		return wp_date( 'Y-m-d', $timestamp + ( $add_days * DAY_IN_SECONDS ) );
	}

	private function next_business_day() {
		return self::get_default_pickup_date();
	}

	private function sanitize_pickup_date( $date ) {
		$date = sanitize_text_field( $date );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $this->next_business_day();
		}

		return $date;
	}
}
