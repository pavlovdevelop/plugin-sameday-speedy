<?php
/**
 * A1POST settings screen.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A1POST admin screen.
 */
class A1post_Admin {

	/**
	 * Settings group.
	 */
	const SETTINGS_GROUP = 'sameday_a1post_settings_group';
	const NONCE_KEY      = 'a1post_admin_nonce';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_order_meta_box' ) );
		add_action( 'wp_ajax_a1post_create_label', array( $this, 'ajax_create_label' ) );
		add_action( 'admin_post_a1post_print_label', array( $this, 'handle_print_label' ) );
		add_action( 'admin_post_a1post_delete_label', array( $this, 'handle_delete_label' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_auto_create_label' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_auto_create_label' ), 20 );
	}

	/**
	 * Add the submenu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'A1POST международни доставки', 'sameday-woocommerce-bg' ),
			__( 'A1POST', 'sameday-woocommerce-bg' ),
			'manage_woocommerce',
			'sameday-woocommerce-bg-a1post',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( self::SETTINGS_GROUP, A1post_Tariff::OPTION, array( 'A1post_Tariff', 'sanitize' ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = A1post_Tariff::get_settings();
		$labels   = A1post_Tariff::get_zone_labels();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-info inline" style="margin:16px 0;">
				<p>
					<?php esc_html_e( 'A1POST се предлага само за адреси извън България. За български адреси се показват Speedy и Sameday.', 'sameday-woocommerce-bg' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Важно:', 'sameday-woocommerce-bg' ); ?></strong>
					<?php esc_html_e( 'A1POST няма публично API. Докато не получим API достъп от тях, товарителниците се създават ръчно в портала на A1POST, а тук се задава само тарифата, по която се калкулира цената в checkout.', 'sameday-woocommerce-bg' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<h2><?php esc_html_e( 'Показване в checkout', 'sameday-woocommerce-bg' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="a1post_service_label"><?php esc_html_e( 'Име на услугата', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="text"
									id="a1post_service_label"
									class="regular-text"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[service_label]"
									value="<?php echo esc_attr( $settings['service_label'] ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_delivery_note"><?php esc_html_e( 'Текст под опцията', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<textarea
									id="a1post_delivery_note"
									class="large-text"
									rows="2"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[delivery_note]"
								><?php echo esc_textarea( $settings['delivery_note'] ); ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Тарифа по зони', 'sameday-woocommerce-bg' ); ?></h2>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							'Цените са в %s. Всеки започнат килограм над включеното тегло се таксува като цял. Зона без списък с държави важи за всички останали държави.',
							$currency
						)
					);
					?>
				</p>

				<?php foreach ( A1post_Tariff::get_zone_codes() as $zone_code ) : ?>
					<?php $zone = $settings['zones'][ $zone_code ]; ?>
					<h3><?php echo esc_html( isset( $labels[ $zone_code ] ) ? $labels[ $zone_code ] : $zone_code ); ?></h3>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Активна', 'sameday-woocommerce-bg' ); ?></th>
								<td>
									<label>
										<input
											type="checkbox"
											name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[zones][<?php echo esc_attr( $zone_code ); ?>][enabled]"
											value="yes"
											<?php checked( $zone['enabled'], 'yes' ); ?>
										/>
										<?php esc_html_e( 'Предлагай A1POST за тази зона', 'sameday-woocommerce-bg' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Държави', 'sameday-woocommerce-bg' ); ?></th>
								<td>
									<textarea
										class="large-text code"
										rows="3"
										name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[zones][<?php echo esc_attr( $zone_code ); ?>][countries]"
									><?php echo esc_textarea( implode( ', ', (array) $zone['countries'] ) ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'ISO кодове от по две букви, разделени със запетая. Оставете празно, за да важи за всички останали държави.', 'sameday-woocommerce-bg' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Включено тегло (кг)', 'sameday-woocommerce-bg' ); ?></th>
								<td>
									<input
										type="number"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[zones][<?php echo esc_attr( $zone_code ); ?>][included_weight]"
										value="<?php echo esc_attr( $zone['included_weight'] ); ?>"
									/>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Базова цена', 'sameday-woocommerce-bg' ); ?></th>
								<td>
									<input
										type="number"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[zones][<?php echo esc_attr( $zone_code ); ?>][base]"
										value="<?php echo esc_attr( $zone['base'] ); ?>"
									/>
									<span class="description"><?php echo esc_html( $currency ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Цена за всеки допълнителен кг', 'sameday-woocommerce-bg' ); ?></th>
								<td>
									<input
										type="number"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[zones][<?php echo esc_attr( $zone_code ); ?>][extra_kg]"
										value="<?php echo esc_attr( $zone['extra_kg'] ); ?>"
									/>
									<span class="description"><?php echo esc_html( $currency ); ?></span>
								</td>
							</tr>
						</tbody>
					</table>
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Достъп до A1POST', 'sameday-woocommerce-bg' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Данните се пазят за бъдещата автоматична интеграция. Докато A1POST не предостави API, те не се използват за заявки.', 'sameday-woocommerce-bg' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="a1post_account_user"><?php esc_html_e( 'Потребител', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="text"
									id="a1post_account_user"
									class="regular-text"
									autocomplete="off"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[account_user]"
									value="<?php echo esc_attr( $settings['account_user'] ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_account_pass"><?php esc_html_e( 'Парола', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="password"
									id="a1post_account_pass"
									class="regular-text"
									autocomplete="new-password"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[account_pass]"
									value=""
									placeholder="<?php echo ! empty( $settings['account_pass'] ) ? esc_attr__( 'Запазена парола - оставете празно, за да не я променяте', 'sameday-woocommerce-bg' ) : ''; ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_api_key"><?php esc_html_e( 'API ключ', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="text"
									id="a1post_api_key"
									class="regular-text"
									autocomplete="off"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[api_key]"
									value="<?php echo esc_attr( $settings['api_key'] ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_api_url"><?php esc_html_e( 'API адрес', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="url"
									id="a1post_api_url"
									class="regular-text"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[api_url]"
									value="<?php echo esc_attr( $settings['api_url'] ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_label_format"><?php esc_html_e( 'Формат на товарителница', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<select id="a1post_label_format" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[label_format]">
									<option value="pdf_a4" <?php selected( $settings['label_format'], 'pdf_a4' ); ?>>PDF A4</option>
									<option value="pdf_a6" <?php selected( $settings['label_format'], 'pdf_a6' ); ?>>PDF A6</option>
									<option value="zpl" <?php selected( $settings['label_format'], 'zpl' ); ?>>ZPL</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_service_code"><?php esc_html_e( 'A1POST услуга', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<select id="a1post_service_code" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[service_code]">
									<option value="L" <?php selected( $settings['service_code'], 'L' ); ?>>L - tracked package</option>
									<option value="R" <?php selected( $settings['service_code'], 'R' ); ?>>R - tracked + signature</option>
									<option value="U" <?php selected( $settings['service_code'], 'U' ); ?>>U - partial tracking</option>
									<option value="ups" <?php selected( $settings['service_code'], 'ups' ); ?>>UPS Express</option>
									<option value="upsS" <?php selected( $settings['service_code'], 'upsS' ); ?>>UPS Expedited</option>
									<option value="dhl" <?php selected( $settings['service_code'], 'dhl' ); ?>>DHL Express</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_default_weight_kg"><?php esc_html_e( 'Тегло по подразбиране (кг)', 'sameday-woocommerce-bg' ); ?></label></th>
							<td><input type="number" min="0.01" step="0.01" id="a1post_default_weight_kg" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[default_weight_kg]" value="<?php echo esc_attr( $settings['default_weight_kg'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_default_contents"><?php esc_html_e( 'Съдържание по подразбиране', 'sameday-woocommerce-bg' ); ?></label></th>
							<td><input type="text" id="a1post_default_contents" class="regular-text" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[default_contents]" value="<?php echo esc_attr( $settings['default_contents'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_default_hs_code"><?php esc_html_e( 'HS код по подразбиране', 'sameday-woocommerce-bg' ); ?></label></th>
							<td><input type="text" id="a1post_default_hs_code" class="regular-text" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[default_hs_code]" value="<?php echo esc_attr( $settings['default_hs_code'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_ioss"><?php esc_html_e( 'IOSS номер', 'sameday-woocommerce-bg' ); ?></label></th>
							<td><input type="text" id="a1post_ioss" class="regular-text" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[ioss]" value="<?php echo esc_attr( $settings['ioss'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Автоматично създаване', 'sameday-woocommerce-bg' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[auto_create_label]" value="yes" <?php checked( $settings['auto_create_label'], 'yes' ); ?> />
									<?php esc_html_e( 'Създавай A1POST товарителница автоматично, когато A1POST поръчка стане Processing или Completed.', 'sameday-woocommerce-bg' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_sender_name"><?php esc_html_e( 'Подател', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="text"
									id="a1post_sender_name"
									class="regular-text"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[sender_name]"
									value="<?php echo esc_attr( $settings['sender_name'] ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_sender_phone"><?php esc_html_e( 'Телефон на подателя', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="text"
									id="a1post_sender_phone"
									class="regular-text"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[sender_phone]"
									value="<?php echo esc_attr( $settings['sender_phone'] ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="a1post_sender_email"><?php esc_html_e( 'Имейл на подателя', 'sameday-woocommerce-bg' ); ?></label></th>
							<td>
								<input
									type="email"
									id="a1post_sender_email"
									class="regular-text"
									name="<?php echo esc_attr( A1post_Tariff::OPTION ); ?>[sender_email]"
									value="<?php echo esc_attr( $settings['sender_email'] ); ?>"
								/>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Запази настройките', 'sameday-woocommerce-bg' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function register_order_meta_box() {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'a1post_label_meta',
				'A1POST - товарителница',
				array( $this, 'render_order_meta_box' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	public function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order );

		if ( ! $order ) {
			echo '<p>Поръчката не може да бъде заредена.</p>';
			return;
		}

		$manager = new A1post_Shipment_Manager();

		if ( ! $manager->order_uses_a1post( $order ) ) {
			echo '<p>Тази поръчка не е с A1POST доставка.</p>';
			return;
		}

		if ( ! ( new A1post_Api_Client( A1post_Tariff::get_settings() ) )->is_configured() ) {
			echo '<p>Първо въведете A1POST потребител и парола в <a href="' . esc_url( admin_url( 'admin.php?page=sameday-woocommerce-bg-a1post' ) ) . '">A1POST настройките</a>.</p>';
			return;
		}

		$tracking = (string) $order->get_meta( A1post_Shipment_Manager::META_TRACKING );

		if ( '' !== $tracking ) {
			$this->render_order_summary( $order, $tracking );
		} else {
			$this->render_order_form( $order );
		}
	}

	private function render_order_form( $order ) {
		$settings   = A1post_Tariff::get_settings();
		$last_error = (string) $order->get_meta( A1post_Shipment_Manager::META_LAST_ERROR );
		$name       = trim( (string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name() );

		if ( '' === $name ) {
			$name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
		}

		$address_1 = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$address_2 = $order->get_shipping_address_2() ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$city      = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$state     = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$postcode  = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$country   = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		$weight    = max( 1, (int) ceil( (float) $settings['default_weight_kg'] * 1000 ) );
		?>
		<div id="a1post-label-box" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_KEY ) ); ?>">
			<table class="widefat striped">
				<tbody>
					<tr><th>Име:</th><td><input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" style="width:100%;" maxlength="40" /></td></tr>
					<tr><th>Адрес 1:</th><td><input type="text" name="addr1" value="<?php echo esc_attr( $address_1 ); ?>" style="width:100%;" /></td></tr>
					<tr><th>Адрес 2:</th><td><input type="text" name="addr2" value="<?php echo esc_attr( $address_2 ); ?>" style="width:100%;" /></td></tr>
					<tr><th>Област/щат:</th><td><input type="text" name="state" value="<?php echo esc_attr( $state ); ?>" style="width:100%;" /></td></tr>
					<tr><th>Град:</th><td><input type="text" name="city" value="<?php echo esc_attr( $city ); ?>" style="width:100%;" /></td></tr>
					<tr><th>Пощенски код:</th><td><input type="text" name="zip" value="<?php echo esc_attr( $postcode ); ?>" /></td></tr>
					<tr><th>Държава ISO:</th><td><input type="text" name="iso" value="<?php echo esc_attr( $country ); ?>" maxlength="2" /></td></tr>
					<tr><th>Телефон:</th><td><input type="text" name="tel" value="<?php echo esc_attr( $order->get_billing_phone() ); ?>" /></td></tr>
					<tr><th>Имейл:</th><td><input type="email" name="email" value="<?php echo esc_attr( $order->get_billing_email() ); ?>" style="width:100%;" /></td></tr>
					<tr><th>Съдържание:</th><td><input type="text" name="content" value="<?php echo esc_attr( $settings['default_contents'] ); ?>" style="width:100%;" /></td></tr>
					<tr><th>Тегло (грама):</th><td><input type="number" min="1" name="weight" value="<?php echo esc_attr( (string) $weight ); ?>" /></td></tr>
					<tr><th>Стойност:</th><td><input type="number" min="0.01" step="0.01" name="value" value="<?php echo esc_attr( (string) max( 0.01, (float) $order->get_subtotal() ) ); ?>" /></td></tr>
					<tr><th>HS код:</th><td><input type="text" name="hs" value="<?php echo esc_attr( $settings['default_hs_code'] ); ?>" /></td></tr>
					<tr><th>IOSS:</th><td><input type="text" name="ioss" value="<?php echo esc_attr( $settings['ioss'] ); ?>" /></td></tr>
					<tr>
						<th>Услуга:</th>
						<td>
							<select name="service">
								<option value="L" <?php selected( $settings['service_code'], 'L' ); ?>>L</option>
								<option value="R" <?php selected( $settings['service_code'], 'R' ); ?>>R</option>
								<option value="U" <?php selected( $settings['service_code'], 'U' ); ?>>U</option>
								<option value="ups" <?php selected( $settings['service_code'], 'ups' ); ?>>UPS Express</option>
								<option value="upsS" <?php selected( $settings['service_code'], 'upsS' ); ?>>UPS Expedited</option>
								<option value="dhl" <?php selected( $settings['service_code'], 'dhl' ); ?>>DHL</option>
							</select>
							<select name="label_format" style="margin-left:8px;">
								<option value="pdf_a4" <?php selected( $settings['label_format'], 'pdf_a4' ); ?>>PDF A4</option>
								<option value="pdf_a6" <?php selected( $settings['label_format'], 'pdf_a6' ); ?>>PDF A6</option>
								<option value="zpl" <?php selected( $settings['label_format'], 'zpl' ); ?>>ZPL</option>
							</select>
						</td>
					</tr>
					<tr><th>Бележки:</th><td><textarea name="notes" rows="2" style="width:100%;"><?php echo esc_textarea( (string) $order->get_customer_note() ); ?></textarea></td></tr>
				</tbody>
			</table>

			<p>
				<button type="button" class="button button-primary" id="a1post-create-label">Създай A1POST товарителница</button>
				<span id="a1post-label-status" style="margin-left:12px;"></span>
			</p>

			<?php if ( '' !== $last_error ) : ?>
				<p style="color:#b32d2e;">Последна грешка: <?php echo esc_html( $last_error ); ?></p>
			<?php endif; ?>
		</div>
		<script>
		jQuery(function($) {
			var $box = $('#a1post-label-box');
			$box.on('click', '#a1post-create-label', function() {
				var $btn = $(this);
				var data = { action: 'a1post_create_label', nonce: $box.data('nonce'), order_id: $box.data('order-id') };
				$box.find('input[name], select[name], textarea[name]').each(function() {
					data[$(this).attr('name')] = $(this).val();
				});
				$btn.prop('disabled', true);
				$('#a1post-label-status').text('Създаване...').css('color', '#555');
				$.post(ajaxurl, data).done(function(resp) {
					if (resp && resp.success) {
						$('#a1post-label-status').text(resp.data.message || 'Готово.').css('color', '#2e7d32');
						window.location.reload();
					} else {
						$('#a1post-label-status').text((resp && resp.data && resp.data.message) || 'Грешка.').css('color', '#b32d2e');
						$btn.prop('disabled', false);
					}
				}).fail(function() {
					$('#a1post-label-status').text('Грешка при заявката.').css('color', '#b32d2e');
					$btn.prop('disabled', false);
				});
			});
		});
		</script>
		<?php
	}

	private function render_order_summary( $order, $tracking ) {
		$created_at = (int) $order->get_meta( A1post_Shipment_Manager::META_CREATED_AT );
		?>
		<div id="a1post-label-box" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<table class="widefat striped">
				<tbody>
					<tr><th>Номер:</th><td><strong><?php echo esc_html( $tracking ); ?></strong></td></tr>
					<tr><th>Създадена:</th><td><?php echo $created_at ? esc_html( wp_date( 'd.m.Y H:i', $created_at ) ) : '—'; ?></td></tr>
					<tr><th>Печат A4:</th><td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'pdf_a4' ) ); ?>">отвори PDF A4</a></td></tr>
					<tr><th>Печат A6:</th><td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'pdf_a6' ) ); ?>">отвори PDF A6</a></td></tr>
					<tr><th>ZPL:</th><td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'zpl' ) ); ?>">отвори ZPL</a></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'a1post_delete_label_' . $order->get_id() ); ?>
				<input type="hidden" name="action" value="a1post_delete_label" />
				<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>" />
				<?php submit_button( 'Изтрий A1POST товарителница', 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function ajax_create_label() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Поръчката не е намерена.' ) );
		}

		$allowed = array( 'name', 'addr1', 'addr2', 'addr3', 'state', 'city', 'zip', 'iso', 'tel', 'tel2', 'email', 'content', 'weight', 'ioss', 'hs', 'value', 'notes', 'label_format', 'service' );
		$data    = array();

		foreach ( $allowed as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$data[ $key ] = wc_clean( wp_unslash( $_POST[ $key ] ) );
			}
		}

		$result = ( new A1post_Shipment_Manager() )->generate_for_order( $order, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'         => sprintf( 'A1POST товарителница %s беше създадена.', $result['tracking_number'] ),
				'tracking_number' => $result['tracking_number'],
			)
		);
	}

	public function handle_print_label() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'a1post_print_label_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_die( esc_html__( 'Поръчката не е намерена.', 'sameday-woocommerce-bg' ) );
		}

		$format = isset( $_GET['label_format'] ) ? sanitize_text_field( wp_unslash( $_GET['label_format'] ) ) : 'pdf_a4';
		$body   = ( new A1post_Shipment_Manager() )->print_label( $order, $format );

		if ( is_wp_error( $body ) ) {
			wp_die( esc_html( $body->get_error_message() ) );
		}

		$tracking = (string) $order->get_meta( A1post_Shipment_Manager::META_TRACKING );
		$is_zpl   = 'zpl' === strtolower( $format );

		nocache_headers();
		header( 'Content-Type: ' . ( $is_zpl ? 'text/plain; charset=utf-8' : 'application/pdf' ) );
		header( 'Content-Disposition: inline; filename="a1post-' . sanitize_file_name( $tracking ?: (string) $order_id ) . ( $is_zpl ? '.zpl' : '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF/ZPL.
		exit;
	}

	public function handle_delete_label() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_admin_referer( 'a1post_delete_label_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_die( esc_html__( 'Поръчката не е намерена.', 'sameday-woocommerce-bg' ) );
		}

		$result = ( new A1post_Shipment_Manager() )->delete_label( $order );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	public function maybe_auto_create_label( $order_id ) {
		if ( 'yes' !== A1post_Tariff::get( 'auto_create_label', 'no' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_meta( A1post_Shipment_Manager::META_TRACKING ) ) {
			return;
		}

		$manager = new A1post_Shipment_Manager();

		if ( ! $manager->order_uses_a1post( $order ) ) {
			return;
		}

		$manager->generate_for_order( $order );
	}

	private function build_print_url( $order, $format ) {
		return add_query_arg(
			array(
				'action'       => 'a1post_print_label',
				'order_id'     => $order->get_id(),
				'label_format' => $format,
				'_wpnonce'     => wp_create_nonce( 'a1post_print_label_' . $order->get_id() ),
			),
			admin_url( 'admin-post.php' )
		);
	}
}
