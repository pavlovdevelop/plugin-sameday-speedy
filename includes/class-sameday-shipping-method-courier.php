<?php
/**
 * Sameday Courier Shipping Method
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sameday Courier Shipping Method Class
 *
 * @since      1.0.0
 */
class Sameday_Shipping_Method_Courier extends WC_Shipping_Method {

	/**
	 * Selected Sameday service for this shipping method instance.
	 *
	 * @var string
	 */
	protected $service_name;

	/**
	 * Constructor for the shipping method class
	 *
	 * @param int $instance_id
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'sameday_courier';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Sameday Куриер 24 часа', 'sameday-woocommerce-bg' );
		$this->method_description = __( 'Доставка с куриер на Sameday в рамките на 24 часа', 'sameday-woocommerce-bg' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		// Define user set variables
		$this->title              = $this->method_title;
		$this->cost               = '3.02';
		$this->additional_kg_cost = '0.20';
		$this->service_name       = 'Courier 24h';

		// Actions
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

		// Load the settings
		$this->init();
	}

	/**
	 * Initialize user set variables
	 */
	public function init() {
		// Load the settings API
		$this->init_form_fields();

		// Save settings in admin if you have any defined
		$this->init_settings();

		// Define user set variables
		$this->title              = $this->get_option( 'title', $this->method_title );
		$this->cost               = $this->get_option( 'cost', '3.02' );
		$this->additional_kg_cost = $this->get_option( 'additional_kg_cost', '0.20' );
		$this->service_name       = $this->get_option( 'service_name', 'Courier 24h' );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'enabled' => array(
				'title'   => __( 'Активиран', 'sameday-woocommerce-bg' ),
				'type'    => 'checkbox',
				'label'   => __( 'Активирай този метод за доставка', 'sameday-woocommerce-bg' ),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __( 'Заглавие', 'sameday-woocommerce-bg' ),
				'type'        => 'text',
				'description' => __( 'Заглавието, което клиентите виждат при избор на метод за доставка.', 'sameday-woocommerce-bg' ),
				'default'     => __( 'Sameday Куриер 24 часа', 'sameday-woocommerce-bg' ),
				'desc_tip'    => true,
			),
			'service_name' => array(
				'title'       => __( 'Sameday Service', 'sameday-woocommerce-bg' ),
				'type'        => 'text',
				'description' => __( 'Internal Sameday service name or code for this shipping method instance.', 'sameday-woocommerce-bg' ),
				'default'     => 'Courier 24h',
				'desc_tip'    => true,
			),
			'cost' => array(
				'title'       => __( 'Базова цена (EUR)', 'sameday-woocommerce-bg' ),
				'type'        => 'number',
				'placeholder' => '3.02',
				'min'         => '0',
				'step'        => '0.01',
				'description' => __( 'Базова цена в евро за първите 3 кг.', 'sameday-woocommerce-bg' ),
				'default'     => '3.02',
				'desc_tip'    => true,
			),
			'additional_kg_cost' => array(
				'title'       => __( 'Цена за допълнителен кг (EUR)', 'sameday-woocommerce-bg' ),
				'type'        => 'number',
				'placeholder' => '0.20',
				'min'         => '0',
				'step'        => '0.01',
				'description' => __( 'Цена в евро за всеки килограм над 3 кг.', 'sameday-woocommerce-bg' ),
				'default'     => '0.20',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Calculate shipping
	 *
	 * @param array $package
	 */
	public function calculate_shipping( $package = array() ) {
		$weight = $this->get_package_weight( $package );

		// Calculate price
		$price_calculator = new Sameday_Price_Calculator();
		$cost = $price_calculator->calculate_courier_price( $weight, $this->cost, $this->additional_kg_cost );

		// Register the rate
		$rate = array(
			'id'        => $this->get_rate_id(),
			'label'     => $this->title,
			'cost'      => $cost,
			'meta_data' => array(
				'sameday_service' => $this->service_name,
			),
			'package'   => $package,
		);

		$this->add_rate( $rate );
	}

	/**
	 * Get package weight
	 *
	 * @param array $package
	 * @return float
	 */
	private function get_package_weight( $package ) {
		$weight = 0;

		foreach ( $package['contents'] as $item_id => $values ) {
			$_product = $values['data'];
			$weight  += $_product->get_weight() * $values['quantity'];
		}

		return wc_get_weight( $weight, 'kg' );
	}
}
