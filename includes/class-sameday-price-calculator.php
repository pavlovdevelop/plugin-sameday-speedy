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
		);
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
	 * Check whether the selected service qualifies for free shipping.
	 *
	 * @param string $service_code   Service code.
	 * @param float  $cart_subtotal  Cart subtotal in store currency.
	 * @return bool
	 */
	public function qualifies_for_free_shipping( $service_code, $cart_subtotal ) {
		if ( ! $this->is_free_shipping_service( $service_code ) ) {
			return false;
		}

		$provider  = $this->get_service_provider( $service_code );
		$threshold = $this->get_free_shipping_threshold( $provider );

		return $threshold > 0 && (float) $cart_subtotal >= $threshold;
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
	 * @param string     $service_code Service code.
	 * @param float      $weight Order weight in kg.
	 * @param float|null $cart_subtotal Optional cart subtotal in store currency.
	 * @return float
	 */
	public function calculate_service_price( $service_code, $weight, $cart_subtotal = null ) {
		$weight = max( 0, (float) $weight );

		if ( null !== $cart_subtotal && $this->qualifies_for_free_shipping( $service_code, $cart_subtotal ) ) {
			return 0.0;
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
			),
			'pricing'   => array(
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
