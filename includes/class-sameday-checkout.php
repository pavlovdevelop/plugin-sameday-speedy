<?php
/**
 * Checkout delivery selector handler.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout delivery selector class.
 */
class Sameday_Checkout {

	/**
	 * Session key for checkout delivery data.
	 */
	const SESSION_KEY = 'sameday_delivery_selection';

	/**
	 * Name of the delivery fee line in cart totals.
	 */
	const FEE_NAME = 'Доставка';

	/**
	 * Price calculator instance.
	 *
	 * @var Sameday_Price_Calculator
	 */
	private $price_calculator;

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
	 * Destination country forced by the caller (used by the AJAX re-render).
	 *
	 * @var string
	 */
	private $country_override = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->price_calculator          = new Sameday_Price_Calculator();
		$this->location_repository       = new Sameday_Location_Repository();
		$this->speedy_location_repository = new Speedy_Location_Repository();
	}

	/**
	 * Force the destination country used while rendering.
	 *
	 * @param string $country ISO-2 country code.
	 * @return void
	 */
	public function set_country_override( $country ) {
		$this->country_override = strtoupper( trim( (string) $country ) );
	}

	/**
	 * Render delivery fields in the billing section.
	 *
	 * @param WC_Checkout|null $checkout Checkout instance.
	 * @return void
	 */
	public function render_delivery_fields( $checkout = null ) {
		if ( ! $this->is_checkout_delivery_active() ) {
			return;
		}

		$selection                 = $this->get_delivery_selection();
		$provider_options          = $this->get_enabled_provider_options();
		$services_by_provider      = $this->get_enabled_services_by_provider();
		$selection                 = $this->apply_single_option_defaults( $selection, $provider_options, $services_by_provider );
		$selected_service          = $this->price_calculator->get_service_definition( $selection['service'] );
		$cart_weight               = $this->get_cart_weight();
		$cart_subtotal             = $this->get_cart_subtotal();
		$context                   = $this->get_delivery_context( $selection );
		$country                   = $context['country'];
		$card_threshold            = sameday_get_card_free_shipping_threshold();
		$card_payment_scope        = sameday_get_free_shipping_payment_scope();
		// The hint is only true for destinations the rule actually covers, so an
		// order shipped abroad never advertises free shipping it will not get.
		$card_rule_active          = sameday_is_card_free_shipping_enabled()
			&& $card_threshold > 0
			&& sameday_is_free_shipping_country( $country );
		$a1post_note               = (string) A1post_Tariff::get( 'delivery_note', '' );
		$delivery_price            = 0;
		$show_price                = false;
		$show_free_shipping        = false;
		$free_shipping_by_card     = false;
		$selected_easybox_location = null;
		$selected_speedy_location  = null;

		if ( ! empty( $selection['easybox_location'] ) ) {
			$selected_easybox_location = $this->location_repository->get_location_by_id( $selection['easybox_location'] );
		}

		if ( ! empty( $selection['speedy_city_id'] ) && ! empty( $selection['speedy_location'] ) ) {
			$selected_speedy_location = $this->speedy_location_repository->get_location_by_id(
				$selection['speedy_city_id'],
				$selection['speedy_location'],
				$this->get_speedy_service_type( $selection['service'] )
			);
		}

		if ( ! empty( $selection['service'] ) && $this->is_selection_complete( $selection ) ) {
			$show_free_shipping    = $this->price_calculator->qualifies_for_free_shipping( $selection['service'], $cart_subtotal, $context );
			$free_shipping_by_card = 'card' === $this->price_calculator->get_free_shipping_reason( $selection['service'], $cart_subtotal, $context );
			$delivery_price        = $this->price_calculator->calculate_service_price( $selection['service'], $cart_weight, $cart_subtotal, $context );
			$show_price            = ! $show_free_shipping && $delivery_price > 0;
		}

		include SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'templates/checkout/easybox-fields.php';
	}

	/**
	 * Render the delivery fields into a string for the AJAX refresh.
	 *
	 * @param string $country Destination country.
	 * @return string
	 */
	public function get_delivery_fields_markup( $country = '' ) {
		$this->set_country_override( $country );

		ob_start();
		$this->render_delivery_fields();

		return (string) ob_get_clean();
	}

	/**
	 * Context that influences pricing: destination country, payment method and Speedy destination.
	 *
	 * @param array|null $selection Delivery selection; defaults to the session selection.
	 * @return array<string, mixed>
	 */
	public function get_delivery_context( $selection = null ) {
		if ( ! is_array( $selection ) ) {
			$selection = $this->get_delivery_selection();
		}

		return array(
			'country'          => $this->get_destination_country(),
			'payment_method'   => $this->get_chosen_payment_method(),
			// Destination, used to quote the real Speedy price.
			'speedy_location'  => isset( $selection['speedy_location'] ) ? $selection['speedy_location'] : 0,
			'speedy_door_city' => isset( $selection['speedy_door_city'] ) ? $selection['speedy_door_city'] : '',
		);
	}

	/**
	 * Resolve the destination country for the current request.
	 *
	 * @return string
	 */
	private function get_destination_country() {
		if ( '' !== $this->country_override ) {
			return $this->country_override;
		}

		return sameday_get_customer_country();
	}

	/**
	 * Resolve the payment method the customer has currently selected.
	 *
	 * @return string
	 */
	private function get_chosen_payment_method() {
		if ( isset( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = wc_clean( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( '' !== $posted ) {
				return (string) $posted;
			}
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$chosen = WC()->session->get( 'chosen_payment_method' );

			if ( ! empty( $chosen ) ) {
				return (string) $chosen;
			}
		}

		// Before the first order review refresh nothing is stored yet, so mirror
		// the gateway WooCommerce itself pre-selects.
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$available = WC()->payment_gateways()->get_available_payment_gateways();

			if ( ! empty( $available ) ) {
				return (string) key( $available );
			}
		}

		return '';
	}

	/**
	 * Update delivery selection in session during checkout refresh.
	 *
	 * @param string $posted_data Serialized checkout data.
	 * @return void
	 */
	public function update_delivery_session( $posted_data ) {
		$parsed_data = array();

		parse_str( wp_unslash( $posted_data ), $parsed_data );
		$selection = $this->sanitize_delivery_selection( $parsed_data );
		$selection = $this->apply_single_option_defaults(
			$selection,
			$this->get_enabled_provider_options(),
			$this->get_enabled_services_by_provider()
		);
		$this->save_delivery_selection_to_session( $selection );
	}

	/**
	 * Validate checkout delivery selection.
	 *
	 * @return void
	 */
	public function validate_delivery_selection() {
		if ( ! $this->is_checkout_delivery_active() ) {
			return;
		}

		$selection = $this->sanitize_delivery_selection( $_POST );
		$selection = $this->apply_single_option_defaults(
			$selection,
			$this->get_enabled_provider_options(),
			$this->get_enabled_services_by_provider()
		);
		$this->save_delivery_selection_to_session( $selection );

		if ( empty( $selection['provider'] ) ) {
			wc_add_notice( 'Моля, изберете куриер за доставка.', 'error' );
		}

		if ( empty( $selection['service'] ) ) {
			wc_add_notice( 'Моля, изберете тип доставка.', 'error' );
		}

		if ( 'sameday_easybox' === $selection['service'] ) {
			if ( empty( $selection['easybox_location'] ) ) {
				wc_add_notice( 'Моля, изберете EasyBox автомат от списъка.', 'error' );
			}

			return;
		}

		if ( 'sameday_door' === $selection['service'] ) {
			if ( empty( $selection['sameday_door_city'] ) ) {
				wc_add_notice( 'Моля, въведете населено място за доставка със Sameday.', 'error' );
			}

			if ( empty( $selection['sameday_door_address'] ) ) {
				wc_add_notice( 'Моля, въведете адрес за доставка със Sameday.', 'error' );
			}

			return;
		}

		if ( 'speedy_office' === $selection['service'] || 'speedy_aps' === $selection['service'] ) {
			if ( empty( $selection['speedy_city_id'] ) ) {
				wc_add_notice( 'Моля, изберете населено място за Speedy.', 'error' );
			}

			if ( empty( $selection['speedy_location'] ) ) {
				wc_add_notice( 'Моля, изберете офис или АПС на Speedy от списъка.', 'error' );
			}

			return;
		}

		if ( 'speedy_door' === $selection['service'] ) {
			if ( empty( $selection['speedy_door_city'] ) ) {
				wc_add_notice( 'Моля, въведете населено място за доставка със Speedy.', 'error' );
			}

			if ( empty( $selection['speedy_door_address'] ) ) {
				wc_add_notice( 'Моля, въведете адрес за доставка със Speedy.', 'error' );
			}

			return;
		}

		if ( 'a1post_international' === $selection['service'] ) {
			$country = $this->get_destination_country();

			if ( 'BG' === $country ) {
				wc_add_notice( 'A1POST се използва само за доставки извън България. Моля, изберете куриер за България.', 'error' );

				return;
			}

			if ( ! A1post_Tariff::supports_country( $country ) ) {
				wc_add_notice( 'За избраната държава няма активна тарифа на A1POST. Моля, свържете се с нас.', 'error' );
			}

			if ( empty( $selection['a1post_name'] ) ) {
				wc_add_notice( 'Моля, въведете име на получателя за A1POST доставка.', 'error' );
			}

			if ( empty( $selection['a1post_phone'] ) ) {
				wc_add_notice( 'Моля, въведете телефон за A1POST доставка.', 'error' );
			}

			if ( empty( $selection['a1post_email'] ) || ! is_email( $selection['a1post_email'] ) ) {
				wc_add_notice( 'Моля, въведете валиден имейл за A1POST доставка.', 'error' );
			}

			if ( empty( $selection['a1post_city'] ) ) {
				wc_add_notice( 'Моля, въведете град за A1POST доставка.', 'error' );
			}

			if ( empty( $selection['a1post_postcode'] ) ) {
				wc_add_notice( 'Моля, въведете пощенски код за A1POST доставка.', 'error' );
			}

			if ( empty( $selection['a1post_address_1'] ) ) {
				wc_add_notice( 'Моля, въведете адрес за A1POST доставка.', 'error' );
			}
		}

	}

	/**
	 * Save checkout delivery metadata to the order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_order_meta( $order_id ) {
		$selection = $this->sanitize_delivery_selection( $_POST );
		$selection = $this->apply_single_option_defaults(
			$selection,
			$this->get_enabled_provider_options(),
			$this->get_enabled_services_by_provider()
		);

		if ( empty( $selection['provider'] ) || empty( $selection['service'] ) ) {
			return;
		}

		$provider_options = $this->get_enabled_provider_options();
		$service          = $this->price_calculator->get_service_definition( $selection['service'] );

		if ( ! is_array( $service ) || ! isset( $provider_options[ $selection['provider'] ] ) ) {
			return;
		}

		update_post_meta( $order_id, '_sameday_delivery_provider', $selection['provider'] );
		update_post_meta( $order_id, '_sameday_delivery_provider_label', $provider_options[ $selection['provider'] ] );
		update_post_meta( $order_id, '_sameday_delivery_service', $selection['service'] );
		update_post_meta( $order_id, '_sameday_delivery_service_label', $service['label'] );
		update_post_meta( $order_id, '_sameday_delivery_details', $selection['details'] );

		$context        = $this->get_delivery_context( $selection );
		$cart_subtotal  = $this->get_cart_subtotal();
		$delivery_price = $this->price_calculator->calculate_service_price( $selection['service'], $this->get_cart_weight(), $cart_subtotal, $context );

		update_post_meta( $order_id, '_sameday_delivery_price', $delivery_price );

		$free_shipping_reason = $this->price_calculator->get_free_shipping_reason( $selection['service'], $cart_subtotal, $context );

		if ( '' !== $free_shipping_reason ) {
			update_post_meta( $order_id, '_sameday_free_shipping_reason', $free_shipping_reason );
		}

		if ( 'sameday_easybox' === $selection['service'] && ! empty( $selection['easybox_location'] ) ) {
			update_post_meta( $order_id, '_sameday_easybox_city', $selection['easybox_city'] );
			update_post_meta( $order_id, '_sameday_easybox_location_id', $selection['easybox_location'] );
		}

		if ( 'sameday_door' === $selection['service'] ) {
			update_post_meta( $order_id, '_sameday_door_city', $selection['sameday_door_city'] );
			update_post_meta( $order_id, '_sameday_door_address', $selection['sameday_door_address'] );
		}

		if ( 'speedy_office' === $selection['service'] || 'speedy_aps' === $selection['service'] ) {
			update_post_meta( $order_id, '_sameday_speedy_city_id', $selection['speedy_city_id'] );
			update_post_meta( $order_id, '_sameday_speedy_city_label', $selection['speedy_city_label'] );
			update_post_meta( $order_id, '_sameday_speedy_location_id', $selection['speedy_location'] );
		}

		if ( 'speedy_door' === $selection['service'] ) {
			update_post_meta( $order_id, '_sameday_speedy_door_city', $selection['speedy_door_city'] );
			update_post_meta( $order_id, '_sameday_speedy_door_address', $selection['speedy_door_address'] );
		}

		if ( 'a1post_international' === $selection['service'] ) {
			$zone = A1post_Tariff::get_zone_for_country( $selection['a1post_country'] );

			update_post_meta( $order_id, '_sameday_a1post_country', $selection['a1post_country'] );
			update_post_meta( $order_id, '_sameday_a1post_zone', is_array( $zone ) ? $zone['code'] : '' );
			update_post_meta( $order_id, '_sameday_a1post_name', $selection['a1post_name'] );
			update_post_meta( $order_id, '_sameday_a1post_phone', $selection['a1post_phone'] );
			update_post_meta( $order_id, '_sameday_a1post_email', $selection['a1post_email'] );
			update_post_meta( $order_id, '_sameday_a1post_address_1', $selection['a1post_address_1'] );
			update_post_meta( $order_id, '_sameday_a1post_address_2', $selection['a1post_address_2'] );
			update_post_meta( $order_id, '_sameday_a1post_city', $selection['a1post_city'] );
			update_post_meta( $order_id, '_sameday_a1post_state', $selection['a1post_state'] );
			update_post_meta( $order_id, '_sameday_a1post_postcode', $selection['a1post_postcode'] );
			update_post_meta( $order_id, '_sameday_a1post_notes', $selection['a1post_notes'] );
		}
	}

	/**
	 * Add delivery fee to cart totals.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function add_delivery_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $this->is_checkout_delivery_active() ) {
			return;
		}

		$selection = $this->get_delivery_selection();

		if ( empty( $selection['service'] ) ) {
			return;
		}

		if ( ! $this->is_selection_complete( $selection ) ) {
			return;
		}

		// A provider that is no longer valid for the destination must not be charged.
		$provider_options = $this->get_enabled_provider_options();
		$provider         = $this->price_calculator->get_service_provider( $selection['service'] );

		if ( '' === $provider || ! isset( $provider_options[ $provider ] ) ) {
			return;
		}

		$cart_subtotal = $this->get_cart_subtotal( $cart );
		$context       = $this->get_delivery_context( $selection );

		// Show an explicit free delivery line so the customer sees one clear delivery price.
		if ( $this->price_calculator->qualifies_for_free_shipping( $selection['service'], $cart_subtotal, $context ) ) {
			$cart->add_fee( self::FEE_NAME, 0, false );
			return;
		}

		$amount = $this->price_calculator->calculate_service_price(
			$selection['service'],
			$this->get_cart_weight(),
			$cart_subtotal,
			$context
		);

		if ( $amount <= 0 ) {
			return;
		}

		$cart->add_fee( self::FEE_NAME, $amount, false );
	}

	/**
	 * Show "Безплатна" instead of 0,00 for the free delivery line in totals.
	 *
	 * @param string $fee_html Fee HTML.
	 * @param object $fee      Fee.
	 * @return string
	 */
	public function filter_free_delivery_fee_html( $fee_html, $fee ) {
		if ( isset( $fee->name, $fee->amount ) && self::FEE_NAME === $fee->name && (float) $fee->amount <= 0 ) {
			return '<strong>Безплатна</strong>';
		}

		return $fee_html;
	}

	/**
	 * AJAX: the exact delivery price the cart will charge for a Speedy selection,
	 * so the checkout summary always shows the same number as the totals.
	 *
	 * @return void
	 */
	public function quote_speedy_price() {
		check_ajax_referer( 'sameday_ajax_nonce', 'security' );

		$service = isset( $_POST['service'] ) ? wc_clean( wp_unslash( $_POST['service'] ) ) : '';

		if ( ! in_array( $service, array( 'speedy_office', 'speedy_aps', 'speedy_door' ), true ) ) {
			wp_send_json_error();
		}

		$context = array(
			'country'          => $this->get_destination_country(),
			'payment_method'   => isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->get_chosen_payment_method(),
			'speedy_location'  => isset( $_POST['location'] ) ? absint( wp_unslash( $_POST['location'] ) ) : 0,
			'speedy_door_city' => isset( $_POST['door_city'] ) ? sanitize_text_field( wp_unslash( $_POST['door_city'] ) ) : '',
			'postcode'         => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '',
		);

		$cart_subtotal = $this->get_cart_subtotal();
		$free          = $this->price_calculator->qualifies_for_free_shipping( $service, $cart_subtotal, $context );

		wp_send_json_success(
			array(
				'free'  => $free,
				'price' => $free ? 0 : $this->price_calculator->calculate_service_price( $service, $this->get_cart_weight(), $cart_subtotal, $context ),
			)
		);
	}

	/**
	 * Tell WooCommerce not to render its shipping methods when plugin replaces them.
	 *
	 * @param bool $needs_shipping Current state.
	 * @return bool
	 */
	public function filter_cart_needs_shipping( $needs_shipping ) {
		if ( $this->is_checkout_delivery_active() && sameday_replaces_wc_shipping() ) {
			return false;
		}

		return $needs_shipping;
	}

	/**
	 * Return current delivery selection from session.
	 *
	 * @return array<string, string|int>
	 */
	public function get_delivery_selection() {
		$defaults = $this->get_selection_defaults();

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $defaults;
		}

		$selection = WC()->session->get( self::SESSION_KEY, array() );

		if ( ! is_array( $selection ) ) {
			return $defaults;
		}

		return wp_parse_args( $selection, $defaults );
	}

	/**
	 * Return selection defaults.
	 *
	 * @return array<string, string|int>
	 */
	private function get_selection_defaults() {
		return array(
			'provider'             => '',
			'service'              => '',
			'details'              => '',
			'easybox_city'         => '',
			'easybox_search'       => '',
			'easybox_location'     => 0,
			'sameday_door_city'    => '',
			'sameday_door_address' => '',
			'speedy_city_id'       => 0,
			'speedy_city_label'    => '',
			'speedy_location'      => 0,
			'speedy_door_city'     => '',
			'speedy_door_address'  => '',
			'a1post_country'       => '',
			'a1post_name'          => '',
			'a1post_phone'         => '',
			'a1post_email'         => '',
			'a1post_address_1'     => '',
			'a1post_address_2'     => '',
			'a1post_city'          => '',
			'a1post_state'         => '',
			'a1post_postcode'      => '',
			'a1post_notes'         => '',
		);
	}

	/**
	 * Sanitize and validate selection payload.
	 *
	 * @param array $data Raw data.
	 * @return array<string, string|int>
	 */
	private function sanitize_delivery_selection( $data ) {
		$selection = $this->get_selection_defaults();

		$selection['provider']         = isset( $data['sameday_shipping_provider'] ) ? wc_clean( wp_unslash( $data['sameday_shipping_provider'] ) ) : '';
		$selection['service']          = isset( $data['sameday_shipping_service'] ) ? wc_clean( wp_unslash( $data['sameday_shipping_service'] ) ) : '';
		$selection['details']          = isset( $data['sameday_delivery_details'] ) ? sanitize_textarea_field( wp_unslash( $data['sameday_delivery_details'] ) ) : '';
		$selection['easybox_city']     = isset( $data['sameday_easybox_city'] ) ? sanitize_text_field( wp_unslash( $data['sameday_easybox_city'] ) ) : '';
		$selection['easybox_search']   = isset( $data['sameday_easybox_search'] ) ? sanitize_text_field( wp_unslash( $data['sameday_easybox_search'] ) ) : '';
		$selection['easybox_location'] = isset( $data['sameday_easybox_location'] ) ? absint( wp_unslash( $data['sameday_easybox_location'] ) ) : 0;
		$selection['sameday_door_city']    = isset( $data['sameday_door_city'] ) ? sanitize_text_field( wp_unslash( $data['sameday_door_city'] ) ) : '';
		$selection['sameday_door_address'] = isset( $data['sameday_door_address'] ) ? sanitize_text_field( wp_unslash( $data['sameday_door_address'] ) ) : '';
		$selection['speedy_city_id']       = isset( $data['sameday_speedy_city'] ) ? absint( wp_unslash( $data['sameday_speedy_city'] ) ) : 0;
		$selection['speedy_location']      = isset( $data['sameday_speedy_location'] ) ? absint( wp_unslash( $data['sameday_speedy_location'] ) ) : 0;
		$selection['speedy_door_city']     = isset( $data['speedy_door_city'] ) ? sanitize_text_field( wp_unslash( $data['speedy_door_city'] ) ) : '';
		$selection['speedy_door_address']  = isset( $data['speedy_door_address'] ) ? sanitize_text_field( wp_unslash( $data['speedy_door_address'] ) ) : '';
		$selection['a1post_name']          = isset( $data['a1post_name'] ) ? sanitize_text_field( wp_unslash( $data['a1post_name'] ) ) : '';
		$selection['a1post_phone']         = isset( $data['a1post_phone'] ) ? sanitize_text_field( wp_unslash( $data['a1post_phone'] ) ) : '';
		$selection['a1post_email']         = isset( $data['a1post_email'] ) ? sanitize_email( wp_unslash( $data['a1post_email'] ) ) : '';
		$selection['a1post_address_1']     = isset( $data['a1post_address_1'] ) ? sanitize_text_field( wp_unslash( $data['a1post_address_1'] ) ) : '';
		$selection['a1post_address_2']     = isset( $data['a1post_address_2'] ) ? sanitize_text_field( wp_unslash( $data['a1post_address_2'] ) ) : '';
		$selection['a1post_city']          = isset( $data['a1post_city'] ) ? sanitize_text_field( wp_unslash( $data['a1post_city'] ) ) : '';
		$selection['a1post_state']         = isset( $data['a1post_state'] ) ? sanitize_text_field( wp_unslash( $data['a1post_state'] ) ) : '';
		$selection['a1post_postcode']      = isset( $data['a1post_postcode'] ) ? sanitize_text_field( wp_unslash( $data['a1post_postcode'] ) ) : '';
		$selection['a1post_notes']         = isset( $data['a1post_notes'] ) ? sanitize_textarea_field( wp_unslash( $data['a1post_notes'] ) ) : '';

		$provider_options = $this->get_enabled_provider_options();

		if ( ! isset( $provider_options[ $selection['provider'] ] ) ) {
			$selection['provider'] = '';
		}

		if ( ! $this->is_service_enabled_for_provider( $selection['provider'], $selection['service'] ) ) {
			$selection['service'] = '';
		}

		if ( 'sameday_easybox' === $selection['service'] ) {
			$location = $this->location_repository->get_location_by_id( $selection['easybox_location'] );

			if ( is_array( $location ) ) {
				$selection['easybox_city'] = $location['city'];
				$selection['details']      = $this->format_easybox_details( $location );
			} else {
				$selection['easybox_location'] = 0;
				$selection['details']          = '';
			}
		} else {
			$selection['easybox_city']     = '';
			$selection['easybox_search']   = '';
			$selection['easybox_location'] = 0;
		}

		if ( 'sameday_door' === $selection['service'] ) {
			$selection['details'] = $this->format_sameday_door_details( $selection['sameday_door_city'], $selection['sameday_door_address'] );
		} else {
			$selection['sameday_door_city']    = '';
			$selection['sameday_door_address'] = '';
		}

		if ( 'speedy_office' === $selection['service'] || 'speedy_aps' === $selection['service'] ) {
			$location = $this->speedy_location_repository->get_location_by_id(
				$selection['speedy_city_id'],
				$selection['speedy_location'],
				$this->get_speedy_service_type( $selection['service'] )
			);

			if ( is_array( $location ) ) {
				$selection['speedy_city_label'] = $location['city_label'];
				$selection['details']           = $this->format_speedy_location_details( $location );
			} else {
				$selection['speedy_location'] = 0;
				$selection['details']         = '';
			}
		} else {
			$selection['speedy_city_id']    = 0;
			$selection['speedy_city_label'] = '';
			$selection['speedy_location']   = 0;
		}

		if ( 'speedy_door' === $selection['service'] ) {
			$selection['details'] = $this->format_speedy_door_details( $selection['speedy_door_city'], $selection['speedy_door_address'] );
		} else {
			$selection['speedy_door_city']    = '';
			$selection['speedy_door_address'] = '';
		}

		if ( 'a1post_international' === $selection['service'] ) {
			$selection['a1post_country'] = $this->get_destination_country();
			$selection                  = $this->fill_a1post_fallbacks( $selection, $data );
			$selection['details']        = $this->format_a1post_details( $data, $selection['a1post_country'] );
		} else {
			$selection['a1post_country'] = '';
		}

		return $selection;
	}

	/**
	 * Build the A1POST delivery details from the checkout address.
	 *
	 * A1POST ships to the regular shipping address, so no extra fields are
	 * collected - we only mirror the address into the order meta and emails.
	 *
	 * @param array  $data    Raw posted data.
	 * @param string $country Destination country.
	 * @return string
	 */
	private function format_a1post_details( $data, $country ) {
		$ship_to_different = ! empty( $data['ship_to_different_address'] );
		$prefix            = $ship_to_different ? 'shipping_' : 'billing_';
		$field             = function ( $key ) use ( $data, $prefix ) {
			return isset( $data[ $prefix . $key ] ) ? sanitize_text_field( wp_unslash( $data[ $prefix . $key ] ) ) : '';
		};

		$a1post = $this->fill_a1post_fallbacks( $this->get_selection_defaults(), $data );
		$address = trim( $a1post['a1post_address_1'] . ' ' . $a1post['a1post_address_2'] );
		$city    = $a1post['a1post_city'];
		$postode = $a1post['a1post_postcode'];

		if ( '' === $address && function_exists( 'WC' ) && WC()->customer ) {
			$address = trim( (string) WC()->customer->get_shipping_address_1() . ' ' . (string) WC()->customer->get_shipping_address_2() );
			$city    = '' !== $city ? $city : (string) WC()->customer->get_shipping_city();
			$postode = '' !== $postode ? $postode : (string) WC()->customer->get_shipping_postcode();
		}

		$country_label = $country;

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();

			if ( isset( $countries[ $country ] ) ) {
				$country_label = $countries[ $country ];
			}
		}

		$parts = array_filter(
			array(
				'' !== $country_label ? 'Държава: ' . $country_label : '',
				'' !== $postode ? 'Пощенски код: ' . $postode : '',
				'' !== $city ? 'Населено място: ' . $city : '',
				'' !== $address ? 'Адрес: ' . $address : '',
				'' !== $a1post['a1post_name'] ? 'Получател: ' . $a1post['a1post_name'] : '',
				'' !== $a1post['a1post_phone'] ? 'Телефон: ' . $a1post['a1post_phone'] : '',
				'' !== $a1post['a1post_email'] ? 'Имейл: ' . $a1post['a1post_email'] : '',
				'' !== $a1post['a1post_notes'] ? 'Уточнения: ' . $a1post['a1post_notes'] : '',
			)
		);

		return implode( "\n", $parts );
	}

	/**
	 * Fill A1POST fields from standard WooCommerce checkout fields when present.
	 *
	 * @param array<string, string|int> $selection Current selection.
	 * @param array                     $data      Posted checkout data.
	 * @return array<string, string|int>
	 */
	private function fill_a1post_fallbacks( $selection, $data ) {
		$ship_to_different = ! empty( $data['ship_to_different_address'] );
		$prefix            = $ship_to_different ? 'shipping_' : 'billing_';
		$get               = function ( $key ) use ( $data, $prefix ) {
			if ( isset( $data[ 'a1post_' . $key ] ) && '' !== trim( (string) $data[ 'a1post_' . $key ] ) ) {
				return sanitize_text_field( wp_unslash( $data[ 'a1post_' . $key ] ) );
			}

			if ( isset( $data[ $prefix . $key ] ) && '' !== trim( (string) $data[ $prefix . $key ] ) ) {
				return sanitize_text_field( wp_unslash( $data[ $prefix . $key ] ) );
			}

			if ( 'shipping_' !== $prefix && isset( $data[ 'shipping_' . $key ] ) && '' !== trim( (string) $data[ 'shipping_' . $key ] ) ) {
				return sanitize_text_field( wp_unslash( $data[ 'shipping_' . $key ] ) );
			}

			return '';
		};

		$first = $get( 'first_name' );
		$last  = $get( 'last_name' );

		if ( empty( $selection['a1post_name'] ) ) {
			$selection['a1post_name'] = trim( $first . ' ' . $last );
		}

		if ( empty( $selection['a1post_phone'] ) && isset( $data['billing_phone'] ) ) {
			$selection['a1post_phone'] = sanitize_text_field( wp_unslash( $data['billing_phone'] ) );
		}

		if ( empty( $selection['a1post_email'] ) && isset( $data['billing_email'] ) ) {
			$selection['a1post_email'] = sanitize_email( wp_unslash( $data['billing_email'] ) );
		}

		$map = array(
			'a1post_address_1' => 'address_1',
			'a1post_address_2' => 'address_2',
			'a1post_city'      => 'city',
			'a1post_state'     => 'state',
			'a1post_postcode'  => 'postcode',
		);

		foreach ( $map as $selection_key => $field_key ) {
			if ( empty( $selection[ $selection_key ] ) ) {
				$selection[ $selection_key ] = $get( $field_key );
			}
		}

		if ( empty( $selection['a1post_notes'] ) && isset( $data['a1post_notes'] ) ) {
			$selection['a1post_notes'] = sanitize_textarea_field( wp_unslash( $data['a1post_notes'] ) );
		}

		return $selection;
	}

	/**
	 * Format Sameday door delivery details for storage.
	 *
	 * @param string $city    City or village.
	 * @param string $address Address.
	 * @return string
	 */
	private function format_sameday_door_details( $city, $address ) {
		$parts = array();

		if ( '' !== $city ) {
			$parts[] = 'Населено място: ' . $city;
		}

		if ( '' !== $address ) {
			$parts[] = 'Адрес: ' . $address;
		}

		return implode( "\n", $parts );
	}

	/**
	 * Format one selected Speedy location for order meta and emails.
	 *
	 * @param array<string, mixed> $location Location.
	 * @return string
	 */
	private function format_speedy_location_details( $location ) {
		return implode(
			', ',
			array_filter(
				array(
					(string) $location['name'],
					(string) $location['address'],
				)
			)
		);
	}

	/**
	 * Format Speedy door delivery details for storage.
	 *
	 * @param string $city    City or village.
	 * @param string $address Address.
	 * @return string
	 */
	private function format_speedy_door_details( $city, $address ) {
		$parts = array();

		if ( '' !== $city ) {
			$parts[] = 'Населено място: ' . $city;
		}

		if ( '' !== $address ) {
			$parts[] = 'Адрес: ' . $address;
		}

		return implode( "\n", $parts );
	}

	/**
	 * Return Speedy location service type for repository lookups.
	 *
	 * @param string $service Service code.
	 * @return string
	 */
	private function get_speedy_service_type( $service ) {
		if ( 'speedy_aps' === $service ) {
			return 'aps';
		}

		if ( 'speedy_office' === $service ) {
			return 'office';
		}

		return 'all';
	}

	/**
	 * Whether the selection is complete enough to show price and add fee.
	 *
	 * @param array<string, string|int> $selection Delivery selection.
	 * @return bool
	 */
	private function is_selection_complete( $selection ) {
		if ( empty( $selection['service'] ) ) {
			return false;
		}

		switch ( $selection['service'] ) {
			case 'sameday_easybox':
				return ! empty( $selection['easybox_location'] );

			case 'sameday_door':
				return ! empty( $selection['sameday_door_city'] ) && ! empty( $selection['sameday_door_address'] );

			case 'speedy_office':
			case 'speedy_aps':
				return ! empty( $selection['speedy_city_id'] ) && ! empty( $selection['speedy_location'] );

			case 'speedy_door':
				return ! empty( $selection['speedy_door_city'] ) && ! empty( $selection['speedy_door_address'] );

			case 'a1post_international':
				return true;
		}

		return false;
	}

	/**
	 * Format one selected EasyBox location for order meta and emails.
	 *
	 * @param array<string, mixed> $location Location.
	 * @return string
	 */
	private function format_easybox_details( $location ) {
		return implode(
			', ',
			array_filter(
				array(
					(string) $location['name'],
					(string) $location['city'],
					(string) $location['address'],
				)
			)
		);
	}

	/**
	 * Save selection to session.
	 *
	 * @param array<string, string|int> $selection Sanitized selection.
	 * @return void
	 */
	private function save_delivery_selection_to_session( $selection ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, $selection );
	}

	/**
	 * Return enabled provider options.
	 *
	 * @return array<string, string>
	 */
	private function get_enabled_provider_options() {
		$options = $this->price_calculator->get_provider_options();
		$country = $this->get_destination_country();

		foreach ( array_keys( $options ) as $provider ) {
			if ( ! sameday_is_provider_enabled( $provider ) ) {
				unset( $options[ $provider ] );
				continue;
			}

			// Speedy / Sameday are domestic only, A1POST is offered abroad only.
			if ( ! sameday_provider_supports_country( $provider, $country ) ) {
				unset( $options[ $provider ] );
				continue;
			}

			if ( 'a1post' === $provider && ! A1post_Tariff::supports_country( $country ) ) {
				unset( $options[ $provider ] );
			}
		}

		return $options;
	}

	/**
	 * Return enabled services grouped by provider.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	private function get_enabled_services_by_provider() {
		$services         = $this->price_calculator->get_services_grouped_by_provider();
		$provider_options = $this->get_enabled_provider_options();

		foreach ( array_keys( $services ) as $provider ) {
			if ( ! isset( $provider_options[ $provider ] ) ) {
				unset( $services[ $provider ] );
			}
		}

		return $services;
	}

	/**
	 * Check whether service is enabled for provider.
	 *
	 * @param string $provider Provider code.
	 * @param string $service  Service code.
	 * @return bool
	 */
	private function is_service_enabled_for_provider( $provider, $service ) {
		$services = $this->get_enabled_services_by_provider();

		return ! empty( $provider ) && ! empty( $service ) && isset( $services[ $provider ][ $service ] );
	}

	/**
	 * Auto-select the only available provider/service.
	 *
	 * This keeps international checkout simple: when A1POST is the only valid
	 * option outside Bulgaria, customers do not have to make an extra choice.
	 *
	 * @param array<string, string|int>                    $selection Selection.
	 * @param array<string, string>                        $providers Providers.
	 * @param array<string, array<string, array<string,string>>> $services Services by provider.
	 * @return array<string, string|int>
	 */
	private function apply_single_option_defaults( $selection, $providers, $services ) {
		if ( empty( $selection['provider'] ) && 1 === count( $providers ) ) {
			$selection['provider'] = (string) key( $providers );
		}

		if ( ! empty( $selection['provider'] ) && empty( $selection['service'] ) && ! empty( $services[ $selection['provider'] ] ) && 1 === count( $services[ $selection['provider'] ] ) ) {
			$selection['service'] = (string) key( $services[ $selection['provider'] ] );
		}

		if ( 'a1post_international' === $selection['service'] ) {
			$selection['a1post_country'] = $this->get_destination_country();
		}

		return $selection;
	}

	/**
	 * Whether plugin delivery is active for the current cart.
	 *
	 * @return bool
	 */
	private function is_checkout_delivery_active() {
		return sameday_is_plugin_enabled() && ! empty( $this->get_enabled_provider_options() ) && $this->cart_has_delivery_items();
	}

	/**
	 * Whether the current cart contains non-virtual products.
	 *
	 * @return bool
	 */
	private function cart_has_delivery_items() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
				continue;
			}

			if ( ! $cart_item['data']->is_virtual() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get cart weight in kg.
	 *
	 * @return float
	 */
	private function get_cart_weight() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return (float) wc_get_weight( WC()->cart->get_cart_contents_weight(), 'kg' );
	}

	/**
	 * Get the cart subtotal used for free shipping checks.
	 *
	 * @param WC_Cart|null $cart Cart object.
	 * @return float
	 */
	private function get_cart_subtotal( $cart = null ) {
		if ( ! $cart && function_exists( 'WC' ) && WC()->cart ) {
			$cart = WC()->cart;
		}

		if ( ! $cart || ! is_object( $cart ) ) {
			return 0.0;
		}

		if ( method_exists( $cart, 'get_displayed_subtotal' ) ) {
			$displayed_subtotal = (float) $cart->get_displayed_subtotal();

			if ( $displayed_subtotal > 0 ) {
				return round( $displayed_subtotal, wc_get_price_decimals() );
			}
		}

		if ( method_exists( $cart, 'get_cart_contents_total' ) ) {
			$contents_total = (float) $cart->get_cart_contents_total();

			if ( method_exists( $cart, 'get_cart_contents_tax' ) ) {
				$contents_total_with_tax = $contents_total + (float) $cart->get_cart_contents_tax();

				if ( $contents_total_with_tax > 0 ) {
					return round( $contents_total_with_tax, wc_get_price_decimals() );
				}
			}

			if ( $contents_total > 0 ) {
				return round( $contents_total, wc_get_price_decimals() );
			}
		}

		if ( method_exists( $cart, 'get_subtotal' ) ) {
			$subtotal = (float) $cart->get_subtotal();

			if ( method_exists( $cart, 'get_subtotal_tax' ) ) {
				$subtotal_with_tax = $subtotal + (float) $cart->get_subtotal_tax();

				if ( $subtotal_with_tax > 0 ) {
					return round( $subtotal_with_tax, wc_get_price_decimals() );
				}
			}

			if ( $subtotal > 0 ) {
				return round( $subtotal, wc_get_price_decimals() );
			}
		}

		return 0.0;
	}
}
