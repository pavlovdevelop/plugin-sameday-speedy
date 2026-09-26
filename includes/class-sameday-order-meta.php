<?php
/**
 * Order delivery meta renderer.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order delivery meta handler.
 */
class Sameday_Order_Meta {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_admin_info' ), 10, 1 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_frontend_info' ), 10, 1 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'display_email_info' ), 10, 3 );
	}

	/**
	 * Display delivery info in admin order details.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function display_admin_info( $order ) {
		$data = $this->get_delivery_meta( $order->get_id() );

		if ( empty( $data['provider_label'] ) || empty( $data['service_label'] ) ) {
			return;
		}

		echo '<p><strong>Избрана доставка:</strong></p>';
		echo '<p>Куриер: ' . esc_html( $data['provider_label'] ) . '</p>';
		echo '<p>Услуга: ' . esc_html( $data['service_label'] ) . '</p>';

		if ( ! empty( $data['details'] ) ) {
			echo '<p>Детайли: ' . nl2br( esc_html( $data['details'] ) ) . '</p>';
		}

		if ( '' !== $data['price'] ) {
			echo '<p>Цена: ' . esc_html( (float) $data['price'] <= 0 ? 'Безплатна доставка' : wp_strip_all_tags( wc_price( (float) $data['price'] ) ) ) . '</p>';
		}
	}

	/**
	 * Display delivery info in the customer order view.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function display_frontend_info( $order ) {
		$data = $this->get_delivery_meta( $order->get_id() );

		if ( empty( $data['provider_label'] ) || empty( $data['service_label'] ) ) {
			return;
		}

		echo '<section class="woocommerce-order-delivery-details">';
		echo '<h2>Избрана доставка</h2>';
		echo '<p><strong>Куриер:</strong> ' . esc_html( $data['provider_label'] ) . '</p>';
		echo '<p><strong>Услуга:</strong> ' . esc_html( $data['service_label'] ) . '</p>';

		if ( ! empty( $data['details'] ) ) {
			echo '<p><strong>Детайли:</strong> ' . nl2br( esc_html( $data['details'] ) ) . '</p>';
		}

		if ( '' !== $data['price'] ) {
			echo '<p><strong>Цена:</strong> ' . esc_html( (float) $data['price'] <= 0 ? 'Безплатна доставка' : wp_strip_all_tags( wc_price( (float) $data['price'] ) ) ) . '</p>';
		}

		echo '</section>';
	}

	/**
	 * Display delivery info in emails.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text Plain text flag.
	 * @return void
	 */
	public function display_email_info( $order, $sent_to_admin, $plain_text ) {
		$data = $this->get_delivery_meta( $order->get_id() );

		if ( empty( $data['provider_label'] ) || empty( $data['service_label'] ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\nИзбрана доставка\n";
			echo 'Куриер: ' . $data['provider_label'] . "\n";
			echo 'Услуга: ' . $data['service_label'] . "\n";

			if ( ! empty( $data['details'] ) ) {
				echo 'Детайли: ' . $data['details'] . "\n";
			}

			if ( '' !== $data['price'] ) {
				echo 'Цена: ' . ( (float) $data['price'] <= 0 ? 'Безплатна доставка' : wp_strip_all_tags( wc_price( (float) $data['price'] ) ) ) . "\n";
			}

			return;
		}

		echo '<h2>Избрана доставка</h2>';
		echo '<ul>';
		echo '<li><strong>Куриер:</strong> ' . esc_html( $data['provider_label'] ) . '</li>';
		echo '<li><strong>Услуга:</strong> ' . esc_html( $data['service_label'] ) . '</li>';

		if ( ! empty( $data['details'] ) ) {
			echo '<li><strong>Детайли:</strong> ' . nl2br( esc_html( $data['details'] ) ) . '</li>';
		}

		if ( '' !== $data['price'] ) {
			echo '<li><strong>Цена:</strong> ' . esc_html( (float) $data['price'] <= 0 ? 'Безплатна доставка' : wp_strip_all_tags( wc_price( (float) $data['price'] ) ) ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Read delivery meta for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, string>
	 */
	private function get_delivery_meta( $order_id ) {
		return array(
			'provider_label' => (string) get_post_meta( $order_id, '_sameday_delivery_provider_label', true ),
			'service_label'  => (string) get_post_meta( $order_id, '_sameday_delivery_service_label', true ),
			'details'        => (string) get_post_meta( $order_id, '_sameday_delivery_details', true ),
			'price'          => (string) get_post_meta( $order_id, '_sameday_delivery_price', true ),
		);
	}
}
