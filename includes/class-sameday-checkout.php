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
	 * Constructor.
	 */
	public function __construct() {
		$this->price_calculator          = new Sameday_Price_Calculator();
		$this->location_repository       = new Sameday_Location_Repository();
		$this->speedy_location_repository = new Speedy_Location_Repository();
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
		$selected_service          = $this->price_calculator->get_service_definition( $selection['service'] );
		$cart_weight               = $this->get_cart_weight();
		$cart_subtotal             = $this->get_cart_subtotal();
		$delivery_price            = 0;
		$show_price                = false;
		$show_free_shipping        = false;
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
			$show_free_shipping = $this->price_calculator->qualifies_for_free_shipping( $selection['service'], $cart_subtotal );
			$delivery_price     = $this->price_calculator->calculate_service_price( $selection['service'], $cart_weight, $cart_subtotal );
			$show_price         = ! $show_free_shipping && $delivery_price > 0;
		}

		include SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'templates/checkout/easybox-fields.php';
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
		$this->save_delivery_selection_to_session( $this->sanitize_delivery_selection( $parsed_data ) );
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

	}

	/**
	 * Save checkout delivery metadata to the order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_order_meta( $order_id ) {
		$selection = $this->sanitize_delivery_selection( $_POST );

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
		update_post_meta( $order_id, '_sameday_delivery_price', $this->price_calculator->calculate_service_price( $selection['service'], $this->get_cart_weight(), $this->get_cart_subtotal() ) );

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

		$amount = $this->price_calculator->calculate_service_price( $selection['service'], $this->get_cart_weight(), $this->get_cart_subtotal( $cart ) );

		if ( $amount <= 0 ) {
			return;
		}

		$cart->add_fee( 'Доставка', $amount, false );
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

		foreach ( array_keys( $options ) as $provider ) {
			if ( ! sameday_is_provider_enabled( $provider ) ) {
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
		$services = $this->price_calculator->get_services_grouped_by_provider();

		foreach ( array_keys( $services ) as $provider ) {
			if ( ! sameday_is_provider_enabled( $provider ) ) {
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
