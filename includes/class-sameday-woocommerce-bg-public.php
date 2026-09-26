<?php
/**
 * Public-facing functionality.
 *
 * @package Sameday_Woocommerce_Bg
 */

/**
 * Public-facing functionality.
 */
class Sameday_Woocommerce_Bg_Public {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin slug.
	 * @param string $version     Plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register frontend styles.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}

		wp_enqueue_style( $this->plugin_name, SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL . 'assets/css/frontend.css', array(), $this->version, 'all' );
	}

	/**
	 * Register frontend scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$dependencies = array( 'jquery' );

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
			$dependencies[] = 'selectWoo';
		} elseif ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
			$dependencies[] = 'select2';
		}

		wp_enqueue_script( $this->plugin_name, SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL . 'assets/js/frontend.js', $dependencies, $this->version, true );

		$calculator      = new Sameday_Price_Calculator();
		$weight          = 0.0;
		$cart_subtotal   = 0.0;
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : 'EUR';

		if ( function_exists( 'WC' ) && WC()->cart ) {
			$weight        = (float) wc_get_weight( WC()->cart->get_cart_contents_weight(), 'kg' );
			$cart_subtotal = $this->get_cart_subtotal( WC()->cart );
		}

		wp_localize_script(
			$this->plugin_name,
			'samedayWooCommerceBg',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'sameday_ajax_nonce' ),
				'cartWeight' => $weight,
				'cartSubtotal' => $cart_subtotal,
				'config'     => $calculator->get_frontend_config(),
				'strings'    => array(
					'chooseService'            => 'Изберете тип доставка, за да изчислим цената.',
					'enterDetails'             => 'Въведете данните по избрания офис, автомат, АПС или адрес.',
					'freeShipping'            => 'Вие получавате безплатна доставка!',
					'freeShippingCard'         => 'Доставката е безплатна, защото плащате с карта.',
					'pricePrefix'              => 'Вашата цена за доставка е',
					'currency'                 => $currency_symbol,
					'easyboxChooseCity'        => 'Изберете населено място',
					'easyboxChooseLocation'    => 'Изберете EasyBox автомат',
					'easyboxChooseCityFirst'   => 'Първо изберете населено място',
					'easyboxLoadingCities'     => 'Зареждаме населени места...',
					'easyboxLoadingLocations'  => 'Зареждаме автомати...',
					'easyboxSelectCityOrSearch'=> 'Изберете населено място или търсете',
					'easyboxSearchMin'         => 'Въведете поне 2 символа за търсене в цяла България',
					'easyboxNoResults'         => 'Няма намерени автомати',
					'easyboxLoadError'         => 'Неуспешно зареждане на EasyBox автоматите',
					'speedyChooseCity'         => 'Изберете населено място',
					'speedyChooseCityFirst'    => 'Първо изберете населено място',
					'speedyChooseOffice'       => 'Изберете Speedy офис',
					'speedyChooseAps'          => 'Изберете Speedy АПС',
					'speedyOfficeLabel'        => 'Speedy офис',
					'speedyApsLabel'           => 'Speedy АПС',
					'speedyTypeHelp'           => 'Официалният списък се зарежда от Speedy и съдържа всички офиси и АПС в България. След отваряне на списъка може да започнете да пишете вътре в него.',
					'speedyLoadingCities'      => 'Зареждаме населени места...',
					'speedyLoadingLocations'   => 'Зареждаме локации...',
					'speedyNoResults'          => 'Няма намерени локации на Speedy',
					'speedyLoadError'          => 'Неуспешно зареждане на Speedy локациите',
				),
			)
		);
	}

	/**
	 * Legacy method registration hook kept for compatibility.
	 *
	 * @param array $methods Existing methods.
	 * @return array
	 */
	public function add_shipping_methods( $methods ) {
		return $methods;
	}

	/**
	 * Get the cart subtotal used for free shipping checks.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return float
	 */
	private function get_cart_subtotal( $cart ) {
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
