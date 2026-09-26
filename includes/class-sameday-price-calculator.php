<?php
/**
 * Delivery price calculator.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery price calculator class.
 */
class Sameday_Price_Calculator {

	/**
	 * Official fixed conversion rate after Bulgaria adopted the euro on 1 January 2026.
	 *
	 * 1 EUR = 1.95583 BGN
	 */
	const BGN_PER_EUR = 1.95583;

	/**
	 * Return provider options.
	 *
	 * @return array<string, string>
	 */
	public function get_provider_options() {
		return array(
			'sameday' => 'Доставка със Sameday',
			'speedy'  => 'Доставка със Спиди',
			'a1post'  => 'Доставка с A1POST',
		);
	}

	/**
	 * Return supported service definitions.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_service_definitions() {
		return array(
			'sameday_easybox' => array(
				'provider'     => 'sameday',
				'label'        => 'Доставка до автомат Easybox',
				'rate_label'   => 'Sameday - Easybox',
				'details_label' => 'Easybox автомат',
				'placeholder'  => 'Напр. Easybox Kaufland Стара Загора, бул. Патриарх Евтимий 52',
			),
			'sameday_door' => array(
				'provider'     => 'sameday',
				'label'        => 'Доставка до вратата със Sameday',
				'rate_label'   => 'Sameday - До вратата',
				'details_label' => 'Адрес и указания за доставка',
				'placeholder'  => 'Напр. ул. Иван Вазов 15, вх. Б, ет. 3, звъннете 10 минути по-рано',
			),
			'speedy_office' => array(
				'provider'     => 'speedy',
				'label'        => 'Доставка до офис',
				'rate_label'   => 'Спиди - До офис',
				'details_label' => 'Офис на Спиди',
				'placeholder'  => 'Напр. офис Спиди Стара Загора Център, ул. Хаджи Димитър Асенов 12',
			),
			'speedy_door' => array(
				'provider'     => 'speedy',
				'label'        => 'Доставка до вратата',
				'rate_label'   => 'Спиди - До вратата',
				'details_label' => 'Адрес и указания за доставка',
				'placeholder'  => 'Напр. ул. Иван Вазов 15, вх. Б, ет. 3, звъннете 10 минути по-рано',
			),
			'speedy_aps' => array(
				'provider'     => 'speedy',
				'label'        => 'Доставка до АПС',
				'rate_label'   => 'Спиди - До АПС',
				'details_label' => 'Автомат / АПС на Спиди',
				'placeholder'  => 'Напр. АПС Спиди Park Mall Стара Загора, ниво -1',
			),
			'a1post_international' => array(
				'provider'     => 'a1post',
				'label'        => $this->get_a1post_service_label(),
				'rate_label'   => 'A1POST - Международна доставка',
				'details_label' => 'Адрес за международна доставка',
				'placeholder'  => 'Адресът от поръчката се използва автоматично',
			),
		);
	}

	/**
	 * Configurable A1POST service label.
	 *
	 * @return string
	 */
	private function get_a1post_service_label() {
		if ( ! class_exists( 'A1post_Tariff' ) ) {
			return 'Международна доставка с A1POST';
		}

		$label = (string) A1post_Tariff::get( 'service_label', 'Международна доставка с A1POST' );

		return '' !== $label ? $label : 'Международна доставка с A1POST';
	}

	/**
	 * Return services grouped by provider.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	public function get_services_grouped_by_provider() {
		$grouped = array(
			'sameday' => array(),
			'speedy'  => array(),
			'a1post'  => array(),
		);

		foreach ( $this->get_service_definitions() as $service_code => $service_definition ) {
			$provider = $service_definition['provider'];

			if ( ! isset( $grouped[ $provider ] ) ) {
				$grouped[ $provider ] = array();
			}

			$grouped[ $provider ][ $service_code ] = $service_definition;
		}

		return $grouped;
	}

	/**
	 * Get a single service definition.
	 *
	 * @param string $service_code Service code.
	 * @return array<string, string>|null
	 */
	public function get_service_definition( $service_code ) {
		$definitions = $this->get_service_definitions();

		if ( ! isset( $definitions[ $service_code ] ) ) {
			return null;
		}

		return $definitions[ $service_code ];
	}

	/**
	 * Check whether the provider is valid.
	 *
	 * @param string $provider Provider code.
	 * @return bool
	 */
	public function is_valid_provider( $provider ) {
		$providers = $this->get_provider_options();

		return isset( $providers[ $provider ] );
	}

	/**
	 * Check whether the service is valid for the selected provider.
	 *
	 * @param string $provider Provider code.
	 * @param string $service_code Service code.
	 * @return bool
	 */
	public function is_valid_service_for_provider( $provider, $service_code ) {
		$definition = $this->get_service_definition( $service_code );

		return is_array( $definition ) && isset( $definition['provider'] ) && $provider === $definition['provider'];
	}

	/**
	 * Get the visible service label.
	 *
	 * @param string $service_code Service code.
	 * @return string
	 */
	public function get_service_label( $service_code ) {
		$definition = $this->get_service_definition( $service_code );

		if ( ! is_array( $definition ) ) {
			return '';
		}

		return $definition['label'];
	}

	/**
	 * Return the provider code for a service.
	 *
	 * @param string $service_code Service code.
	 * @return string
	 */
	public function get_service_provider( $service_code ) {
		$definition = $this->get_service_definition( $service_code );

		if ( ! is_array( $definition ) || empty( $definition['provider'] ) ) {
			return '';
		}

		return $definition['provider'];
	}

	/**
	 * Return the configured free shipping threshold for a provider.
	 *
	 * @param string $provider Provider code.
	 * @return float
	 */
	public function get_free_shipping_threshold( $provider ) {
		return sameday_get_free_shipping_threshold( $provider );
	}

	/**
	 * Check whether a service is eligible for free shipping.
	 *
	 * Free shipping is available only for locker / pickup services.
	 *
	 * @param string $service_code Service code.
	 * @return bool
	 */
	public function is_free_shipping_service( $service_code ) {
		return in_array( $service_code, array( 'sameday_easybox', 'speedy_office', 'speedy_aps' ), true );
	}

	/**
	 * Whether free shipping may be granted for this service at all.
	 *
	 * International shipping is always charged: the shop pays the carrier per
	 * destination zone, so no order value makes an A1POST parcel free. On top
	 * of that the destination country has to be inside the configured free
	 * shipping scope, which defaults to the shop's own country.
	 *
	 * @param string               $service_code Service code.
	 * @param array<string, mixed> $context      Optional context (country).
	 * @return bool
	 */
	public function is_free_shipping_eligible( $service_code, $context = array() ) {
		$provider = $this->get_service_provider( $service_code );

		if ( '' === $provider ) {
			return false;
		}

		if ( 'international' === sameday_get_provider_country_scope( $provider ) ) {
			return false;
		}

		return sameday_is_free_shipping_country( $this->resolve_country( $context ) );
	}

	/**
	 * Check whether the selected service qualifies for free shipping.
	 *
	 * Two independent rules can grant it, and both apply only to destinations
	 * inside the free shipping scope (see is_free_shipping_eligible()):
	 *
	 * 1. The order value rule - from a configured order value up. It covers
	 *    every delivery type unless the scope is limited to pickup, and either
	 *    card payments only or every payment method including cash on delivery.
	 * 2. The per-provider threshold - office / locker services only.
	 *
	 * @param string               $service_code  Service code.
	 * @param float                $cart_subtotal Cart subtotal in store currency.
	 * @param array<string, mixed> $context       Optional context (payment_method, country).
	 * @return bool
	 */
	public function qualifies_for_free_shipping( $service_code, $cart_subtotal, $context = array() ) {
		if ( ! $this->get_service_definition( $service_code ) ) {
			return false;
		}

		if ( ! $this->is_free_shipping_eligible( $service_code, $context ) ) {
			return false;
		}

		if ( $this->qualifies_for_card_free_shipping( $service_code, $cart_subtotal, $context ) ) {
			return true;
		}

		if ( ! $this->is_free_shipping_service( $service_code ) ) {
			return false;
		}

		$provider  = $this->get_service_provider( $service_code );
		$threshold = $this->get_free_shipping_threshold( $provider );

		return $threshold > 0 && (float) $cart_subtotal >= $threshold;
	}

	/**
	 * Check the order value free shipping rule.
	 *
	 * Kept under the historic name because the rule started out as
	 * "free shipping when paying by card"; the payment scope setting now
	 * decides whether it stays card only or covers cash on delivery too.
	 *
	 * @param string               $service_code  Service code.
	 * @param float                $cart_subtotal Cart subtotal in store currency.
	 * @param array<string, mixed> $context       Optional context (payment_method, country).
	 * @return bool
	 */
	public function qualifies_for_card_free_shipping( $service_code, $cart_subtotal, $context = array() ) {
		if ( ! sameday_is_card_free_shipping_enabled() ) {
			return false;
		}

		if ( ! $this->is_free_shipping_eligible( $service_code, $context ) ) {
			return false;
		}

		$threshold = sameday_get_card_free_shipping_threshold();

		if ( $threshold <= 0 || (float) $cart_subtotal < $threshold ) {
			return false;
		}

		if ( 'pickup' === sameday_get_card_free_shipping_scope() && ! $this->is_free_shipping_service( $service_code ) ) {
			return false;
		}

		if ( 'all' === sameday_get_free_shipping_payment_scope() ) {
			return true;
		}

		$payment_method = isset( $context['payment_method'] ) ? (string) $context['payment_method'] : '';

		return sameday_is_card_payment_method( $payment_method );
	}

	/**
	 * Why the current selection is free, if it is.
	 *
	 * @param string               $service_code  Service code.
	 * @param float                $cart_subtotal Cart subtotal in store currency.
	 * @param array<string, mixed> $context       Optional context (payment_method, country).
	 * @return string '' | 'card' | 'order_value' | 'threshold'
	 */
	public function get_free_shipping_reason( $service_code, $cart_subtotal, $context = array() ) {
		if ( ! $this->qualifies_for_free_shipping( $service_code, $cart_subtotal, $context ) ) {
			return '';
		}

		if ( ! $this->qualifies_for_card_free_shipping( $service_code, $cart_subtotal, $context ) ) {
			return 'threshold';
		}

		return 'card' === sameday_get_free_shipping_payment_scope() ? 'card' : 'order_value';
	}

	/**
	 * Resolve the destination country from a pricing context.
	 *
	 * @param array<string, mixed> $context Pricing context.
	 * @return string
	 */
	private function resolve_country( $context ) {
		if ( isset( $context['country'] ) && '' !== $context['country'] ) {
			return strtoupper( (string) $context['country'] );
		}

		return sameday_get_customer_country();
	}

	/**
	 * Get the WooCommerce shipping rate label.
	 *
	 * @param string $service_code Service code.
	 * @return string
	 */
	public function get_rate_label( $service_code ) {
		$definition = $this->get_service_definition( $service_code );

		if ( ! is_array( $definition ) ) {
			return 'Доставка';
		}

		return $definition['rate_label'];
	}

	/**
	 * Calculate the final shipping price for a service.
	 *
	 * @param string               $service_code  Service code.
	 * @param float                $weight        Order weight in kg.
	 * @param float|null           $cart_subtotal Optional cart subtotal in store currency.
	 * @param array<string, mixed> $context       Optional context (payment_method, country).
	 * @return float
	 */
	public function calculate_service_price( $service_code, $weight, $cart_subtotal = null, $context = array() ) {
		$weight = max( 0, (float) $weight );

		if ( null !== $cart_subtotal && $this->qualifies_for_free_shipping( $service_code, $cart_subtotal, $context ) ) {
			return 0.0;
		}

		if ( 'a1post_international' === $service_code ) {
			$country = isset( $context['country'] ) && '' !== $context['country']
				? (string) $context['country']
				: sameday_get_customer_country();

			return A1post_Tariff::calculate_price( $country, $weight );
		}

		// Speedy services prefer the cached API rate (per-contract pricing).
		if ( in_array( $service_code, array( 'speedy_office', 'speedy_aps', 'speedy_door' ), true ) ) {
			$api_price = $this->get_cached_speedy_price( $service_code, $weight );

			if ( null !== $api_price ) {
				return $api_price;
			}
		}

		switch ( $service_code ) {
			case 'sameday_easybox':
				return $this->convert_bgn_to_eur( $this->calculate_linear_rate_bgn( $weight, 2.90, 3, 0.40 ) );

			case 'sameday_door':
				return $this->convert_bgn_to_eur( $this->calculate_linear_rate_bgn( $weight, 5.90, 3, 0.40 ) );

			case 'speedy_office':
				return $this->convert_bgn_to_eur( $this->calculate_speedy_rate_bgn( $weight, 'office' ) );

			case 'speedy_door':
				return $this->convert_bgn_to_eur( $this->calculate_speedy_rate_bgn( $weight, 'door' ) );

			case 'speedy_aps':
				return $this->convert_bgn_to_eur( $this->calculate_speedy_rate_bgn( $weight, 'aps' ) );
		}

		return 0.0;
	}

	/**
	 * Look up a cached Speedy API price and return it converted to the
	 * store currency. Returns null when the cache is empty so the caller
	 * falls back to the hardcoded tariff.
	 *
	 * @param string $service_code Service code.
	 * @param float  $weight       Cart weight in kg.
	 * @return float|null
	 */
	private function get_cached_speedy_price( $service_code, $weight ) {
		if ( ! class_exists( 'Speedy_Rate_Cache' ) ) {
			return null;
		}

		$amount = Speedy_Rate_Cache::get_rate( $service_code, $weight );

		if ( null === $amount || $amount <= 0 ) {
			return null;
		}

		$cache    = Speedy_Rate_Cache::get_cache();
		$currency = isset( $cache['currency'] ) ? strtoupper( (string) $cache['currency'] ) : 'BGN';

		if ( 'BGN' === $currency ) {
			return $this->convert_bgn_to_eur( (float) $amount );
		}

		return round( (float) $amount, 2 );
	}

	/**
	 * Backward-compatible EasyBox calculator.
	 *
	 * @param float $weight Weight in kg.
	 * @param float $base_cost Base cost.
	 * @param float $additional_kg_cost Additional kg cost.
	 * @return float
	 */
	public function calculate_easybox_price( $weight, $base_cost, $additional_kg_cost ) {
		return $this->calculate_linear_rate( (float) $weight, (float) $base_cost, 3, (float) $additional_kg_cost );
	}

	/**
	 * Backward-compatible courier calculator.
	 *
	 * @param float $weight Weight in kg.
	 * @param float $base_cost Base cost.
	 * @param float $additional_kg_cost Additional kg cost.
	 * @return float
	 */
	public function calculate_courier_price( $weight, $base_cost, $additional_kg_cost ) {
		return $this->calculate_linear_rate( (float) $weight, (float) $base_cost, 3, (float) $additional_kg_cost );
	}

	/**
	 * Calculate COD fee.
	 *
	 * @param float $order_total Order total.
	 * @param float $cod_percent Percentage.
	 * @param float $minimum_fee Minimum fee.
	 * @return float
	 */
	public function calculate_cod_fee( $order_total, $cod_percent = 1, $minimum_fee = 0.50 ) {
		$fee = ( (float) $order_total * (float) $cod_percent ) / 100;

		if ( $fee < $minimum_fee ) {
			$fee = $minimum_fee;
		}

		return round( $fee, 2 );
	}

	/**
	 * Return the data needed by the checkout script.
	 *
	 * @return array<string, mixed>
	 */
	public function get_frontend_config() {
		return array(
			'conversionRate' => self::BGN_PER_EUR,
			'providers' => $this->get_provider_options(),
			'services'  => $this->get_services_grouped_by_provider(),
			'freeShippingThresholds' => array(
				'sameday' => $this->get_free_shipping_threshold( 'sameday' ),
				'speedy'  => $this->get_free_shipping_threshold( 'speedy' ),
				'a1post'  => $this->get_free_shipping_threshold( 'a1post' ),
			),
			'cardFreeShipping' => array(
				'enabled'      => sameday_is_card_free_shipping_enabled(),
				'threshold'    => sameday_get_card_free_shipping_threshold(),
				'scope'        => sameday_get_card_free_shipping_scope(),
				'paymentScope' => sameday_get_free_shipping_payment_scope(),
				'gateways'     => sameday_get_card_gateway_ids(),
			),
			'freeShipping' => array(
				'countryScope' => sameday_get_free_shipping_country_scope(),
				'countries'    => sameday_get_free_shipping_countries(),
				// Providers that ship abroad are never free, whatever the order value.
				'excludedProviders' => $this->get_international_providers(),
			),
			'country'   => sameday_get_customer_country(),
			'pricing'   => array(
				'a1post' => $this->get_a1post_frontend_pricing(),
				'sameday' => array(
					'includedWeight' => 3,
					'easybox'        => array(
						'base'      => 2.90,
						'additional' => 0.40,
					),
					'door'           => array(
						'base'      => 5.90,
						'additional' => 0.40,
					),
				),
				'speedy'  => array(
					'bands' => array(
						array(
							'max'      => 3,
							'base'     => 4.77,
							'pickup'   => 1.60,
							'delivery' => 4.58,
						),
						array(
							'max'      => 6,
							'base'     => 6.59,
							'pickup'   => 4.58,
							'delivery' => 7.55,
						),
						array(
							'max'      => 10,
							'base'     => 8.14,
							'pickup'   => 4.58,
							'delivery' => 7.55,
						),
						array(
							'max'      => 20,
							'base'     => 14.53,
							'pickup'   => 10.56,
							'delivery' => 10.56,
						),
					),
					'over20' => array(
						'base'      => 14.53,
						'extraKg'   => 0.63,
						'pickup'    => 15.04,
						'delivery'  => 15.04,
						'threshold' => 20,
					),
				),
			),
		);
	}

	/**
	 * Provider codes that only serve destinations outside the shop country.
	 *
	 * @return array<int, string>
	 */
	private function get_international_providers() {
		$providers = array();

		foreach ( array_keys( $this->get_provider_options() ) as $provider ) {
			if ( 'international' === sameday_get_provider_country_scope( $provider ) ) {
				$providers[] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * Return the A1POST zone tariff in the shape the checkout script needs.
	 *
	 * @return array<string, mixed>
	 */
	private function get_a1post_frontend_pricing() {
		$zones    = array();
		$settings = A1post_Tariff::get_settings();

		foreach ( A1post_Tariff::get_zone_codes() as $zone_code ) {
			$zone = $settings['zones'][ $zone_code ];

			if ( 'yes' !== $zone['enabled'] ) {
				continue;
			}

			$zones[] = array(
				'code'           => $zone_code,
				'countries'      => array_map( 'strtoupper', array_map( 'strval', (array) $zone['countries'] ) ),
				'includedWeight' => (float) $zone['included_weight'],
				'base'           => (float) $zone['base'],
				'extraKg'        => (float) $zone['extra_kg'],
			);
		}

		return array( 'zones' => $zones );
	}

	/**
	 * Calculate a simple linear rate.
	 *
	 * @param float $weight Weight in kg.
	 * @param float $base_cost Base cost.
	 * @param float $included_weight Included weight.
	 * @param float $additional_kg_cost Extra kg price.
	 * @return float
	 */
	private function calculate_linear_rate( $weight, $base_cost, $included_weight, $additional_kg_cost ) {
		$price = (float) $base_cost;

		if ( $weight > $included_weight ) {
			$price += ( $weight - $included_weight ) * (float) $additional_kg_cost;
		}

		return round( $price, 2 );
	}

	/**
	 * Calculate a simple linear rate in BGN.
	 *
	 * @param float $weight Weight in kg.
	 * @param float $base_cost Base cost in BGN.
	 * @param float $included_weight Included weight.
	 * @param float $additional_kg_cost Extra kg price in BGN.
	 * @return float
	 */
	private function calculate_linear_rate_bgn( $weight, $base_cost, $included_weight, $additional_kg_cost ) {
		$price = (float) $base_cost;

		if ( $weight > $included_weight ) {
			$price += ( $weight - $included_weight ) * (float) $additional_kg_cost;
		}

		return $price;
	}

	/**
	 * Calculate Speedy domestic shipping.
	 *
	 * The supplied tariff states a base price between office/APS points plus
	 * optional add-ons for pickup from sender address and delivery to address.
	 * For checkout we treat the sender as a business pickup from address.
	 *
	 * @param float  $weight Weight in kg.
	 * @param string $destination Destination type.
	 * @return float
	 */
	private function calculate_speedy_rate_bgn( $weight, $destination ) {
		$band = $this->get_speedy_band( $weight );

		if ( $weight > 20 ) {
			$base_cost = 14.53 + ( ( $weight - 20 ) * 0.63 );
			$pickup    = 15.04;
			$delivery  = 15.04;
		} else {
			$base_cost = $band['base'];
			$pickup    = $band['pickup'];
			$delivery  = $band['delivery'];
		}

		$price = $base_cost + $pickup;

		if ( 'door' === $destination ) {
			$price += $delivery;
		}

		return $price;
	}

	/**
	 * Return the matching Speedy tariff band.
	 *
	 * @param float $weight Weight in kg.
	 * @return array<string, float>
	 */
	private function get_speedy_band( $weight ) {
		$bands = array(
			array(
				'max'      => 3,
				'base'     => 4.77,
				'pickup'   => 1.60,
				'delivery' => 4.58,
			),
			array(
				'max'      => 6,
				'base'     => 6.59,
				'pickup'   => 4.58,
				'delivery' => 7.55,
			),
			array(
				'max'      => 10,
				'base'     => 8.14,
				'pickup'   => 4.58,
				'delivery' => 7.55,
			),
			array(
				'max'      => 20,
				'base'     => 14.53,
				'pickup'   => 10.56,
				'delivery' => 10.56,
			),
		);

		foreach ( $bands as $band ) {
			if ( $weight <= $band['max'] ) {
				return $band;
			}
		}

		return $bands[ count( $bands ) - 1 ];
	}

	/**
	 * Convert BGN to EUR using the official fixed rate.
	 *
	 * @param float $amount_bgn Amount in BGN.
	 * @return float
	 */
	private function convert_bgn_to_eur( $amount_bgn ) {
		return round( (float) $amount_bgn / self::BGN_PER_EUR, 2 );
	}
}
