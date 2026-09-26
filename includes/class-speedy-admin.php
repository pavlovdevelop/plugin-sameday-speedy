<?php
/**
 * Admin glue for the Speedy API integration.
 *
 * Provides the comprehensive Speedy settings page (credentials, profile,
 * sender, parcel defaults, print, return options) and the per-order meta
 * box that lets the merchant create / print / cancel waybills and request
 * a courier pickup — modelled on the mreja.net Speedy plugin layout.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Speedy_Admin {

	const MENU_SLUG = 'sameday-woocommerce-bg-speedy-api';
	const NONCE_KEY = 'speedy_admin_nonce';

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_speedy_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_speedy_refresh_profile', array( $this, 'handle_refresh_profile' ) );
		add_action( 'admin_post_speedy_refresh_rates', array( $this, 'handle_refresh_rates' ) );
		add_action( 'admin_post_speedy_print_waybill', array( $this, 'handle_print_waybill' ) );
		add_action( 'admin_post_speedy_print_voucher', array( $this, 'handle_print_voucher' ) );

		add_action( 'wp_ajax_speedy_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_speedy_load_services', array( $this, 'ajax_load_services' ) );
		add_action( 'wp_ajax_speedy_load_offices', array( $this, 'ajax_load_offices' ) );
		add_action( 'wp_ajax_speedy_create_shipment', array( $this, 'ajax_create_shipment' ) );
		add_action( 'wp_ajax_speedy_cancel_shipment', array( $this, 'ajax_cancel_shipment' ) );
		add_action( 'wp_ajax_speedy_request_pickup', array( $this, 'ajax_request_pickup' ) );

		add_action( 'add_meta_boxes', array( $this, 'register_order_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			'Speedy API',
			'Speedy API',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_assets() {
		$is_settings_page = isset( $_GET['page'] ) && self::MENU_SLUG === $_GET['page'];
		$is_order_screen  = $this->is_order_edit_screen();

		if ( ! $is_settings_page && ! $is_order_screen ) {
			return;
		}

		wp_enqueue_style(
			'speedy-admin',
			SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL . 'assets/css/speedy-admin.css',
			array(),
			SAMEDAY_WOOCOMMERCE_BG_VERSION
		);

		wp_enqueue_script(
			'speedy-admin',
			SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL . 'assets/js/speedy-admin.js',
			array( 'jquery' ),
			SAMEDAY_WOOCOMMERCE_BG_VERSION,
			true
		);

		wp_localize_script(
			'speedy-admin',
			'speedyAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_KEY ),
				'i18n'    => array(
					'confirmCancel'     => 'Сигурни ли сте, че искате да изтриете тази товарителница?',
					'cancelReason'      => 'Причина за изтриването:',
					'creating'          => 'Генериране на товарителница...',
					'cancelling'        => 'Изтриване на товарителница...',
					'requestingPickup'  => 'Заявка на куриер...',
					'pickupOk'          => 'Куриер е заявен успешно.',
					'testing'           => 'Тест на връзката...',
					'connectionOk'      => 'Връзката със Speedy е успешна.',
					'connectionFail'    => 'Неуспешна връзка със Speedy.',
					'genericError'      => 'Възникна грешка.',
					'loadingServices'   => 'Зареждам услугите от Speedy...',
					'loadingOffices'    => 'Зареждам офисите от Speedy...',
					'servicesLoaded'    => 'Услугите са заредени.',
					'officesLoaded'     => 'Офисите са заредени.',
					'servicesEmpty'     => 'Speedy не върна услуги. Проверете дали имате активен договор.',
					'officesEmpty'      => 'Speedy не върна офиси.',
					'choosePlaceholder' => '— Изберете услуга —',
				),
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Settings page
	 * --------------------------------------------------------------------- */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		$settings        = Speedy_Settings::get_all();
		$profile         = Speedy_Settings::get_profile();
		$services_cache  = Speedy_Settings::get_services_cache();
		$offices_cache   = Speedy_Settings::get_offices_cache();
		$contract        = isset( $profile['contract_clients'] ) && is_array( $profile['contract_clients'] ) ? $profile['contract_clients'] : array();
		$notice          = $this->consume_notice();
		?>
		<div class="wrap speedy-settings">
			<h1>Speedy API интеграция</h1>
			<p>Свържете магазина с профила Ви в Speedy, за да създавате товарителници, да заявявате куриер и да принтирате пътни листове директно от поръчката.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<h2>Профил</h2>
			<table class="widefat striped" style="max-width:960px;">
				<tbody>
					<tr>
						<th style="width:220px;">Статус</th>
						<td>
							<?php if ( Speedy_Settings::has_credentials() && ! empty( $profile['client_id'] ) ) : ?>
								<span style="color:#2e7d32;font-weight:600;">Свързан</span>
							<?php elseif ( Speedy_Settings::has_credentials() ) : ?>
								<span style="color:#b26a00;font-weight:600;">Има credentials, но профилът не е зареден</span>
							<?php else : ?>
								<span style="color:#b32d2e;font-weight:600;">Не е настроен</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Speedy clientId</th>
						<td><?php echo $profile['client_id'] ? esc_html( (string) $profile['client_id'] ) : '—'; ?></td>
					</tr>
					<tr>
						<th>Име на клиента</th>
						<td><?php echo $this->extract_client_name( $profile['client'] ); ?></td>
					</tr>
					<tr>
						<th>Клиенти по договора</th>
						<td>
							<?php if ( ! empty( $contract ) ) : ?>
								<ul style="margin:0;">
									<?php foreach ( $contract as $contract_client ) : ?>
										<li>
											<?php
											$id   = isset( $contract_client['id'] ) ? (int) $contract_client['id'] : 0;
											$name = isset( $contract_client['name'] ) ? (string) $contract_client['name'] : '';
											echo esc_html( ( $id ? '#' . $id . ' ' : '' ) . $name );
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Последно зареждане</th>
						<td><?php echo $profile['fetched_at'] ? esc_html( wp_date( 'd.m.Y H:i', (int) $profile['fetched_at'] ) ) : '—'; ?></td>
					</tr>
					<?php if ( ! empty( $profile['last_error'] ) ) : ?>
						<tr>
							<th>Последна грешка</th>
							<td style="color:#b32d2e;"><?php echo esc_html( $profile['last_error'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'speedy_refresh_profile' ); ?>
					<input type="hidden" name="action" value="speedy_refresh_profile" />
					<?php submit_button( 'Обнови профила (зарежда клиенти, услуги и офиси)', 'secondary', 'submit', false ); ?>
				</form>
				<button type="button" class="button" id="speedy-test-connection" style="margin-left:8px;">Тествай връзката</button>
				<button type="button" class="button" id="speedy-load-offices" style="margin-left:8px;">Обнови офисите</button>
				<span id="speedy-test-result" style="margin-left:12px;"></span>
				<span id="speedy-offices-status" style="margin-left:12px;"></span>
			</p>

			<hr />

			<h2>Цени от API (за чекаута)</h2>
			<?php
			$rate_cache = Speedy_Rate_Cache::get_cache();
			$next_cron  = wp_next_scheduled( Speedy_Rate_Cache::CRON_HOOK );
			?>
			<p>Цените за Speedy услугите в чекаута се вземат от <code>/calculate</code> endpoint-а на Speedy и се кешират локално, така че чекаутът остава бърз. Кешът се обновява автоматично два пъти на ден.</p>

			<table class="widefat striped" style="max-width:960px;">
				<tbody>
					<tr>
						<th style="width:220px;">Последно обновяване</th>
						<td><?php echo $rate_cache['fetched_at'] ? esc_html( wp_date( 'd.m.Y H:i', (int) $rate_cache['fetched_at'] ) ) : '— Все още не е изпълнено —'; ?></td>
					</tr>
					<tr>
						<th>Валута</th>
						<td><?php echo esc_html( (string) $rate_cache['currency'] ); ?></td>
					</tr>
					<tr>
						<th>Следващо автоматично обновяване</th>
						<td><?php echo $next_cron ? esc_html( wp_date( 'd.m.Y H:i', (int) $next_cron ) ) : '— Не е насрочено —'; ?></td>
					</tr>
					<?php if ( ! empty( $rate_cache['last_error'] ) ) : ?>
						<tr>
							<th>Грешки от последен sync</th>
							<td style="color:#b32d2e;"><?php echo esc_html( $rate_cache['last_error'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $rate_cache['rates'] ) ) : ?>
				<table class="widefat striped" style="max-width:960px;margin-top:12px;">
					<thead>
						<tr>
							<th>Тегло до (kg)</th>
							<th>До офис</th>
							<th>До АПС</th>
							<th>До адрес</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$bands_index = array();
						foreach ( $rate_cache['rates'] as $code => $rates_list ) {
							foreach ( (array) $rates_list as $row ) {
								if ( isset( $row['max'] ) ) {
									$bands_index[ (string) $row['max'] ] = true;
								}
							}
						}
						$bands_keys = array_keys( $bands_index );
						usort( $bands_keys, function ( $a, $b ) { return (float) $a <=> (float) $b; } );

						$find_price = function ( $code, $max ) use ( $rate_cache ) {
							if ( empty( $rate_cache['rates'][ $code ] ) ) {
								return null;
							}

							foreach ( $rate_cache['rates'][ $code ] as $row ) {
								if ( isset( $row['max'] ) && (string) $row['max'] === (string) $max ) {
									return isset( $row['price'] ) ? (float) $row['price'] : null;
								}
							}

							return null;
						};

						foreach ( $bands_keys as $max ) :
							?>
							<tr>
								<td><?php echo esc_html( $max ); ?></td>
								<td><?php $price = $find_price( 'speedy_office', $max ); echo null === $price ? '—' : esc_html( number_format( $price, 2, '.', '' ) ); ?></td>
								<td><?php $price = $find_price( 'speedy_aps', $max );    echo null === $price ? '—' : esc_html( number_format( $price, 2, '.', '' ) ); ?></td>
								<td><?php $price = $find_price( 'speedy_door', $max );   echo null === $price ? '—' : esc_html( number_format( $price, 2, '.', '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:12px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'speedy_refresh_rates' ); ?>
					<input type="hidden" name="action" value="speedy_refresh_rates" />
					<?php submit_button( 'Обнови цените от Speedy сега', 'secondary', 'submit', false ); ?>
				</form>
				<span class="description" style="margin-left:8px;">Полезно след като промените service ID или опции по договора. Иначе става автоматично два пъти на ден.</span>
			</p>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'speedy_save_settings' ); ?>
				<input type="hidden" name="action" value="speedy_save_settings" />

				<h2>Достъп до API</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="speedy_username">Потребителско име</label></th>
						<td><input type="text" id="speedy_username" name="speedy[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="speedy_password">Парола</label></th>
						<td>
							<input type="password" id="speedy_password" name="speedy[password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['password'] ? '•••••••• (запазена)' : ''; ?>" />
							<p class="description">Оставете празно, за да запазите вече въведената парола.</p>
						</td>
					</tr>
					<tr>
						<th><label for="speedy_client_system_id">clientSystemId (по избор)</label></th>
						<td><input type="text" id="speedy_client_system_id" name="speedy[client_system_id]" value="<?php echo esc_attr( $settings['client_system_id'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="speedy_language">Език</label></th>
						<td>
							<select id="speedy_language" name="speedy[language]">
								<option value="BG" <?php selected( $settings['language'], 'BG' ); ?>>BG</option>
								<option value="EN" <?php selected( $settings['language'], 'EN' ); ?>>EN</option>
							</select>
						</td>
					</tr>
				</table>

				<h2>Изпращач</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="speedy_sender_name">Име на контакта</label></th>
						<td><input type="text" id="speedy_sender_name" name="speedy[sender_name]" value="<?php echo esc_attr( $settings['sender_name'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="speedy_sender_phone">Телефон</label></th>
						<td><input type="text" id="speedy_sender_phone" name="speedy[sender_phone]" value="<?php echo esc_attr( $settings['sender_phone'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="speedy_sender_email">Имейл</label></th>
						<td><input type="email" id="speedy_sender_email" name="speedy[sender_email]" value="<?php echo esc_attr( $settings['sender_email'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Изпрати от</th>
						<td>
							<label style="margin-right:16px;"><input type="radio" name="speedy[sender_type]" value="address" <?php checked( $settings['sender_type'], 'address' ); ?> /> Адрес (от договора)</label>
							<label><input type="radio" name="speedy[sender_type]" value="office" <?php checked( $settings['sender_type'], 'office' ); ?> /> Офис на Speedy</label>
						</td>
					</tr>
					<tr>
						<th><label for="speedy_sender_client_id">Адрес на изпращач (контрактен клиент)</label></th>
						<td>
							<?php $sender_candidates = $this->get_sender_client_options(); ?>
							<select id="speedy_sender_client_id" name="speedy[sender_client_id]" class="regular-text">
								<option value="">— Изберете адрес —</option>
								<?php foreach ( $sender_candidates as $candidate ) : ?>
									<option value="<?php echo esc_attr( (string) $candidate['id'] ); ?>" <?php selected( (string) $settings['sender_client_id'], (string) $candidate['id'] ); ?>>
										<?php echo esc_html( '#' . $candidate['id'] . ' — ' . $candidate['name'] ); ?>
									</option>
								<?php endforeach; ?>
								<?php if ( '' !== $settings['sender_client_id'] && ! $this->contract_has_id( $sender_candidates, $settings['sender_client_id'] ) ) : ?>
									<option value="<?php echo esc_attr( $settings['sender_client_id'] ); ?>" selected="selected">#<?php echo esc_html( $settings['sender_client_id'] ); ?> (запазен)</option>
								<?php endif; ?>
							</select>
							<p class="description">
								<?php if ( empty( $sender_candidates ) ) : ?>
									Списъкът е празен — натиснете „Обнови профила", за да го заредите.
								<?php else : ?>
									Списъкът съдържа Вашия профил + клиентите по договора (зарежда се от „Обнови профила").
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="speedy_sender_office_id">Офис на изпращач</label></th>
						<td>
							<select id="speedy_sender_office_id" name="speedy[sender_office_id]" class="regular-text speedy-office-select" data-current="<?php echo esc_attr( (string) $settings['sender_office_id'] ); ?>">
								<option value="">— Изберете офис —</option>
								<?php foreach ( $offices_cache['list'] as $office ) : ?>
									<option value="<?php echo esc_attr( (string) $office['id'] ); ?>" <?php selected( (string) $settings['sender_office_id'], (string) $office['id'] ); ?>>
										<?php echo esc_html( $office['label'] ); ?>
									</option>
								<?php endforeach; ?>
								<?php if ( '' !== $settings['sender_office_id'] && ! $this->office_in_list( $offices_cache['list'], $settings['sender_office_id'] ) ) : ?>
									<option value="<?php echo esc_attr( $settings['sender_office_id'] ); ?>" selected="selected">#<?php echo esc_html( $settings['sender_office_id'] ); ?> (запазен)</option>
								<?php endif; ?>
							</select>
							<p class="description">
								<?php if ( ! empty( $offices_cache['fetched_at'] ) ) : ?>
									Заредени <?php echo (int) count( $offices_cache['list'] ); ?> офиса на <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $offices_cache['fetched_at'] ) ); ?>.
								<?php else : ?>
									Натиснете „Обнови офисите" за зареждане.
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2>Услуги (Service ID)</h2>
				<p class="description">
					Изберете коя Speedy услуга да се използва за всеки тип доставка от чекаута.
					<?php if ( ! empty( $services_cache['fetched_at'] ) ) : ?>
						<br /><em>Заредени <?php echo (int) count( $services_cache['list'] ); ?> услуги на <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $services_cache['fetched_at'] ) ); ?>.</em>
					<?php endif; ?>
				</p>
				<p>
					<button type="button" class="button" id="speedy-load-services">Зареди услугите от Speedy</button>
					<span id="speedy-services-status" style="margin-left:12px;"></span>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="speedy_service_id_office">До офис (Speedy office)</label></th>
						<td><?php $this->render_service_select( 'service_id_office', $settings['service_id_office'], $services_cache['list'] ); ?></td>
					</tr>
					<tr>
						<th><label for="speedy_service_id_aps">До АПС</label></th>
						<td><?php $this->render_service_select( 'service_id_aps', $settings['service_id_aps'], $services_cache['list'] ); ?></td>
					</tr>
					<tr>
						<th><label for="speedy_service_id_door">До адрес</label></th>
						<td><?php $this->render_service_select( 'service_id_door', $settings['service_id_door'], $services_cache['list'] ); ?></td>
					</tr>
				</table>

				<h2>Колет по подразбиране</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="speedy_default_parcels">Брой колети</label></th>
						<td><input type="number" min="1" id="speedy_default_parcels" name="speedy[default_parcels]" value="<?php echo esc_attr( (int) $settings['default_parcels'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="speedy_default_weight">Тегло (kg)</label></th>
						<td><input type="number" min="0.1" step="0.1" id="speedy_default_weight" name="speedy[default_weight]" value="<?php echo esc_attr( $settings['default_weight'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th>Размери (см)</th>
						<td>
							<input type="number" min="0" name="speedy[default_length]" value="<?php echo esc_attr( (string) $settings['default_length'] ); ?>" placeholder="дължина" class="small-text" />
							×
							<input type="number" min="0" name="speedy[default_width]" value="<?php echo esc_attr( (string) $settings['default_width'] ); ?>" placeholder="широчина" class="small-text" />
							×
							<input type="number" min="0" name="speedy[default_height]" value="<?php echo esc_attr( (string) $settings['default_height'] ); ?>" placeholder="височина" class="small-text" />
						</td>
					</tr>
					<tr>
						<th><label for="speedy_default_pack">Опаковка</label></th>
						<td><input type="text" id="speedy_default_pack" name="speedy[default_pack]" value="<?php echo esc_attr( $settings['default_pack'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="speedy_default_contents">Съдържание</label></th>
						<td><input type="text" id="speedy_default_contents" name="speedy[default_contents]" value="<?php echo esc_attr( $settings['default_contents'] ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<h2>Платец и допълнителни услуги</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label>Платец на куриерската услуга</label></th>
						<td><?php $this->render_payer_select( 'payer_courier', $settings['payer_courier'] ); ?></td>
					</tr>
					<tr>
						<th><label>Платец на обявена стойност</label></th>
						<td><?php $this->render_payer_select( 'payer_declared', $settings['payer_declared'] ); ?></td>
					</tr>
					<tr>
						<th><label>Платец на наложен платеж</label></th>
						<td><?php $this->render_payer_select( 'payer_cod', $settings['payer_cod'] ); ?></td>
					</tr>
					<tr>
						<th>Наложен платеж</th>
						<td><label><input type="checkbox" name="speedy[cod_on]" value="yes" <?php checked( $settings['cod_on'], 'yes' ); ?> /> Автоматично добавяй COD за поръчки с плащане при доставка</label></td>
					</tr>
					<tr>
						<th>Обявена стойност</th>
						<td><label><input type="checkbox" name="speedy[declared_value_on]" value="yes" <?php checked( $settings['declared_value_on'], 'yes' ); ?> /> Декларирай стойността на пратката</label></td>
					</tr>
					<tr>
						<th>Чупливо по подразбиране</th>
						<td><label><input type="checkbox" name="speedy[fragile_default]" value="yes" <?php checked( $settings['fragile_default'], 'yes' ); ?> /> Маркирай пратката като чуплива</label></td>
					</tr>
					<tr>
						<th>Съботен разнос по подразбиране</th>
						<td><label><input type="checkbox" name="speedy[saturday_default]" value="yes" <?php checked( $settings['saturday_default'], 'yes' ); ?> /> Активирай съботна доставка по подразбиране</label></td>
					</tr>
				</table>

				<h2>Печат</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="speedy_paper_size">Размер на товарителницата</label></th>
						<td>
							<select name="speedy[paper_size]" id="speedy_paper_size">
								<option value="A6" <?php selected( $settings['paper_size'], 'A6' ); ?>>A6 (стикер)</option>
								<option value="A4" <?php selected( $settings['paper_size'], 'A4' ); ?>>A4</option>
								<option value="A4_4xA6" <?php selected( $settings['paper_size'], 'A4_4xA6' ); ?>>A4 (4 x A6)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Принтиране на копие</th>
						<td>
							<select name="speedy[print_copy]">
								<option value="none" <?php selected( $settings['print_copy'], 'none' ); ?>>Без копие</option>
								<option value="same" <?php selected( $settings['print_copy'], 'same' ); ?>>Копие на същата страница</option>
								<option value="new"  <?php selected( $settings['print_copy'], 'new' ); ?>>Копие на нова страница</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Отваряне на PDF</th>
						<td>
							<select name="speedy[pdf_target]">
								<option value="_blank" <?php selected( $settings['pdf_target'], '_blank' ); ?>>В нов прозорец</option>
								<option value="_self"  <?php selected( $settings['pdf_target'], '_self' ); ?>>В същия прозорец</option>
							</select>
						</td>
					</tr>
				</table>

				<h2>Връщане при отказ (ОПП)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th>Активирай ОПП</th>
						<td><label><input type="checkbox" name="speedy[opp_on]" value="yes" <?php checked( $settings['opp_on'], 'yes' ); ?> /> Отвори преди да платиш</label></td>
					</tr>
					<tr>
						<th>Само за НП и ППП</th>
						<td><label><input type="checkbox" name="speedy[opp_only_cod]" value="yes" <?php checked( $settings['opp_only_cod'], 'yes' ); ?> /> Прилагай ОПП само за поръчки с наложен платеж</label></td>
					</tr>
					<tr>
						<th>Платец на доставка при връщане</th>
						<td><?php $this->render_payer_select( 'return_payer_opp', $settings['return_payer_opp'] ); ?></td>
					</tr>
					<tr>
						<th>Услуга на доставка при връщане</th>
						<td><?php $this->render_service_select( 'return_service_opp', $settings['return_service_opp'], $services_cache['list'] ); ?></td>
					</tr>
				</table>

				<h2>Ваучер за връщане</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th>Активирай ваучер за връщане</th>
						<td><label><input type="checkbox" name="speedy[voucher_on]" value="yes" <?php checked( $settings['voucher_on'], 'yes' ); ?> /></label></td>
					</tr>
					<tr>
						<th>Услуга на доставка при връщане</th>
						<td><?php $this->render_service_select( 'voucher_service_id', $settings['voucher_service_id'], $services_cache['list'] ); ?></td>
					</tr>
					<tr>
						<th>Платец</th>
						<td><?php $this->render_payer_select( 'voucher_payer', $settings['voucher_payer'] ); ?></td>
					</tr>
					<tr>
						<th>Срок на валидност (дни)</th>
						<td><input type="number" min="1" name="speedy[voucher_validity]" value="<?php echo esc_attr( (string) $settings['voucher_validity'] ); ?>" class="small-text" /></td>
					</tr>
				</table>

				<h2>Имейл за проследяване</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th>Изпращай имейл</th>
						<td><label><input type="checkbox" name="speedy[tracking_email_on]" value="yes" <?php checked( $settings['tracking_email_on'], 'yes' ); ?> /> Изпращай имейл за проследяване след създаване на пратката</label></td>
					</tr>
					<tr>
						<th>„От" име</th>
						<td><input type="text" name="speedy[tracking_email_from_name]" value="<?php echo esc_attr( $settings['tracking_email_from_name'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>„От" адрес</th>
						<td><input type="email" name="speedy[tracking_email_from_addr]" value="<?php echo esc_attr( $settings['tracking_email_from_addr'] ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<?php submit_button( 'Запази Speedy настройките' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'speedy_save_settings' );

		$input = isset( $_POST['speedy'] ) && is_array( $_POST['speedy'] ) ? wp_unslash( $_POST['speedy'] ) : array();
		Speedy_Settings::save( $input );

		$this->set_notice( 'success', 'Настройките за Speedy API са запазени.' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_refresh_profile() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'speedy_refresh_profile' );

		$client  = new Speedy_Api_Client( Speedy_Settings::get_credentials() );
		$profile = $client->fetch_profile();

		if ( is_wp_error( $profile ) ) {
			Speedy_Settings::save_profile(
				array(
					'last_error' => $profile->get_error_message(),
					'fetched_at' => time(),
				)
			);
			$this->set_notice( 'error', $profile->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		Speedy_Settings::save_profile(
			array(
				'client_id'        => (int) $profile['client_id'],
				'client'           => (array) $profile['client'],
				'contract_clients' => (array) $profile['contract_clients'],
				'fetched_at'       => time(),
				'last_error'       => '',
			)
		);

		$services     = $client->get_services();
		$services_msg = '';

		if ( ! is_wp_error( $services ) ) {
			Speedy_Settings::save_services( $services );
			$services_msg = sprintf( ' Услуги: %d.', count( $services ) );
		}

		$offices     = $client->find_offices();
		$offices_msg = '';

		if ( ! is_wp_error( $offices ) ) {
			Speedy_Settings::save_offices( $offices );
			$offices_msg = sprintf( ' Офиси: %d.', count( $offices ) );
		}

		// Kick off an initial rate sync now that we have profile + services.
		$rates_msg = '';

		if ( (int) Speedy_Settings::get( 'service_id_office', 0 ) > 0
			|| (int) Speedy_Settings::get( 'service_id_aps', 0 ) > 0
			|| (int) Speedy_Settings::get( 'service_id_door', 0 ) > 0 ) {
			$rate_result = Speedy_Rate_Cache::sync( $client );

			if ( ! is_wp_error( $rate_result ) ) {
				$rates_msg = ' Цените са кеширани.';
			}
		}

		$this->set_notice(
			'success',
			sprintf( 'Профилът е зареден успешно (clientId: %d).%s%s%s', (int) $profile['client_id'], $services_msg, $offices_msg, $rates_msg )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_refresh_rates() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'speedy_refresh_rates' );

		$result = Speedy_Rate_Cache::sync();

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', 'Неуспешна синхронизация на цените: ' . $result->get_error_message() );
		} else {
			$total = 0;

			foreach ( (array) $result['rates'] as $list ) {
				$total += is_array( $list ) ? count( $list ) : 0;
			}

			$message = sprintf( 'Цените са обновени. Заредени %d ценови точки за %d услуги.', $total, count( $result['rates'] ) );

			if ( ! empty( $result['last_error'] ) ) {
				$message .= ' Предупреждения: ' . $result['last_error'];
			}

			$this->set_notice( 'success', $message );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * AJAX
	 * --------------------------------------------------------------------- */

	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$client    = new Speedy_Api_Client( Speedy_Settings::get_credentials() );
		$client_id = $client->get_own_client_id();

		if ( is_wp_error( $client_id ) ) {
			wp_send_json_error( array( 'message' => $client_id->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'   => sprintf( 'Връзката е успешна. clientId: %d', (int) $client_id ),
				'client_id' => (int) $client_id,
			)
		);
	}

	public function ajax_load_services() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$client   = new Speedy_Api_Client( Speedy_Settings::get_credentials() );
		$services = $client->get_services();

		if ( is_wp_error( $services ) ) {
			wp_send_json_error( array( 'message' => $services->get_error_message() ) );
		}

		Speedy_Settings::save_services( $services );

		$cache = Speedy_Settings::get_services_cache();

		wp_send_json_success(
			array(
				'message'  => sprintf( 'Заредени са %d услуги от Speedy.', count( $cache['list'] ) ),
				'services' => $cache['list'],
			)
		);
	}

	public function ajax_load_offices() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$client  = new Speedy_Api_Client( Speedy_Settings::get_credentials() );
		$offices = $client->find_offices();

		if ( is_wp_error( $offices ) ) {
			wp_send_json_error( array( 'message' => $offices->get_error_message() ) );
		}

		Speedy_Settings::save_offices( $offices );
		$cache = Speedy_Settings::get_offices_cache();

		wp_send_json_success(
			array(
				'message' => sprintf( 'Заредени са %d офиса от Speedy.', count( $cache['list'] ) ),
				'offices' => $cache['list'],
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Order meta box
	 * --------------------------------------------------------------------- */

	public function register_order_meta_box() {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'speedy_shipment_meta',
				'Спиди - подготовка на пратка',
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

		$manager = new Speedy_Shipment_Manager();

		if ( ! $manager->order_uses_speedy( $order ) ) {
			echo '<p>Тази поръчка не е със Speedy куриер.</p>';
			return;
		}

		if ( ! Speedy_Settings::has_credentials() ) {
			echo '<p>Първо настройте Speedy API в <a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">Speedy API</a>.</p>';
			return;
		}

		$shipment_id = (string) $order->get_meta( Speedy_Shipment_Manager::META_SHIPMENT_ID );

		if ( '' === $shipment_id ) {
			$this->render_order_form( $order );
		} else {
			$this->render_order_summary( $order, $shipment_id );
		}
	}

	private function render_order_form( $order ) {
		$settings           = Speedy_Settings::get_all();
		$profile            = Speedy_Settings::get_profile();
		$sender_candidates  = $this->get_sender_client_options();
		$offices_cache      = Speedy_Settings::get_offices_cache();
		$last_error         = (string) $order->get_meta( Speedy_Shipment_Manager::META_LAST_ERROR );

		$contact_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$contact_phone = $order->get_billing_phone();
		$contact_email = $order->get_billing_email();

		$cod_amount = $order->get_total();
		$is_cod     = false !== strpos( strtolower( (string) $order->get_payment_method() ), 'cod' )
			|| in_array( strtolower( (string) $order->get_payment_method() ), array( 'cheque', 'cheque_payment', 'naloji', 'nalozhen', 'nalozhen_platej' ), true );

		$parcels   = (int) $settings['default_parcels'];
		$weight    = (float) $settings['default_weight'];
		$pack      = (string) $settings['default_pack'];
		$contents  = $this->compose_default_contents( $order, $settings['default_contents'] );
		$pickup_date = Speedy_Shipment_Manager::get_default_pickup_date();
		?>
		<div id="speedy-shipment-box" class="speedy-shipment-form" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<table class="speedy-grid">
				<tr>
					<th>Лице за контакт:</th>
					<td><input type="text" name="contact_name" value="<?php echo esc_attr( $contact_name ); ?>" /></td>
				</tr>
				<tr>
					<th>Телефон:</th>
					<td><input type="text" name="contact_phone" value="<?php echo esc_attr( $contact_phone ); ?>" /></td>
				</tr>
				<tr>
					<th>Имейл:</th>
					<td><input type="email" name="contact_email" value="<?php echo esc_attr( $contact_email ); ?>" /></td>
				</tr>
				<tr>
					<th>Изпрати от:</th>
					<td>
						<select name="sender_type" id="speedy-sender-type">
							<option value="address" <?php selected( $settings['sender_type'], 'address' ); ?>>Адрес</option>
							<option value="office"  <?php selected( $settings['sender_type'], 'office' ); ?>>Офис</option>
						</select>
					</td>
				</tr>
				<tr class="speedy-row-sender-address">
					<th>Адрес на изпращача:</th>
					<td>
						<select name="sender_client_id">
							<?php if ( ! empty( $sender_candidates ) ) : ?>
								<?php foreach ( $sender_candidates as $candidate ) : ?>
									<option value="<?php echo esc_attr( (string) $candidate['id'] ); ?>" <?php selected( (string) $settings['sender_client_id'], (string) $candidate['id'] ); ?>>
										<?php echo esc_html( '#' . $candidate['id'] . ' — ' . $candidate['name'] ); ?>
									</option>
								<?php endforeach; ?>
							<?php else : ?>
								<option value="">— Първо обновете профила —</option>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr class="speedy-row-sender-office">
					<th>Офис на изпращач:</th>
					<td>
						<select name="sender_office_id" class="speedy-office-select" data-current="<?php echo esc_attr( (string) $settings['sender_office_id'] ); ?>">
							<option value="">— моля изберете —</option>
							<?php foreach ( $offices_cache['list'] as $office ) : ?>
								<option value="<?php echo esc_attr( (string) $office['id'] ); ?>" <?php selected( (string) $settings['sender_office_id'], (string) $office['id'] ); ?>>
									<?php echo esc_html( $office['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>Платец:</th>
					<td>
						<select name="payer_courier">
							<option value="SENDER"    <?php selected( $settings['payer_courier'], 'SENDER' ); ?>>Изпращач</option>
							<option value="RECIPIENT" <?php selected( $settings['payer_courier'], 'RECIPIENT' ); ?>>Получател</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Дата на взимане:</th>
					<td>
						<input type="date" name="pickup_date" value="<?php echo esc_attr( $pickup_date ); ?>" />
						<p class="description">Тази дата се изпраща към Speedy като <code>pickupDate</code>. Ако в профила гледате пратки по дата на взимане, търсете товарителницата на тази дата.</p>
					</td>
				</tr>
				<tr>
					<th>Брой пакети:</th>
					<td><input type="number" min="1" id="speedy-mb-parcels" name="parcels" value="<?php echo esc_attr( (string) $parcels ); ?>" /></td>
				</tr>
				<tr>
					<th>Пратки:</th>
					<td>
						<table class="speedy-parcels" id="speedy-parcels-table">
							<thead>
								<tr>
									<th>пратка №</th>
									<th>тегло в кг</th>
									<th>дължина в см</th>
									<th>широчина в см</th>
									<th>височина в см</th>
								</tr>
							</thead>
							<tbody>
								<?php for ( $i = 1; $i <= $parcels; $i++ ) : ?>
									<tr>
										<td><?php echo (int) $i; ?></td>
										<td><input type="number" step="0.01" min="0" name="parcel_rows[<?php echo (int) ( $i - 1 ); ?>][weight]" value="<?php echo esc_attr( (string) round( $weight / max( 1, $parcels ), 3 ) ); ?>" /></td>
										<td><input type="number" min="0" name="parcel_rows[<?php echo (int) ( $i - 1 ); ?>][length]" value="<?php echo esc_attr( (string) $settings['default_length'] ); ?>" /></td>
										<td><input type="number" min="0" name="parcel_rows[<?php echo (int) ( $i - 1 ); ?>][width]" value="<?php echo esc_attr( (string) $settings['default_width'] ); ?>" /></td>
										<td><input type="number" min="0" name="parcel_rows[<?php echo (int) ( $i - 1 ); ?>][height]" value="<?php echo esc_attr( (string) $settings['default_height'] ); ?>" /></td>
									</tr>
								<?php endfor; ?>
							</tbody>
						</table>
					</td>
				</tr>
				<tr>
					<th>Опаковка:</th>
					<td><input type="text" name="pack" value="<?php echo esc_attr( $pack ); ?>" /></td>
				</tr>
				<tr>
					<th>Описание:</th>
					<td><input type="text" name="contents" value="<?php echo esc_attr( $contents ); ?>" /></td>
				</tr>
				<tr>
					<th>Забележка за пратката:</th>
					<td><textarea name="note" rows="2"><?php echo esc_textarea( (string) $order->get_customer_note() ); ?></textarea></td>
				</tr>
				<tr>
					<th>Плащане при доставка:</th>
					<td>
						<select name="cod_type">
							<option value="cash" selected="selected">НП (наложен платеж)</option>
							<option value="opp">ППП (пощенски паричен превод)</option>
						</select>
						сума:
						<input type="number" step="0.01" min="0" name="cod_amount" value="<?php echo esc_attr( (string) $cod_amount ); ?>" />
						<label style="margin-left:8px;"><input type="checkbox" name="cod_on" value="yes" <?php checked( $is_cod ); ?> /> добавяй COD</label>
					</td>
				</tr>
				<tr>
					<th>Опции преди плащане (ОПП):</th>
					<td>
						<select name="opp_on">
							<option value="no"  <?php selected( $settings['opp_on'], 'no' ); ?>>Не</option>
							<option value="yes" <?php selected( $settings['opp_on'], 'yes' ); ?>>Отвори</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Застраховка:</th>
					<td>
						<select name="declared_value_on">
							<option value="no"  <?php selected( $settings['declared_value_on'], 'no' ); ?>>Не</option>
							<option value="yes" <?php selected( $settings['declared_value_on'], 'yes' ); ?>>Да</option>
						</select>
						сума:
						<input type="number" step="0.01" min="0" name="declared_value_amount" value="<?php echo esc_attr( (string) $order->get_subtotal() ); ?>" />
					</td>
				</tr>
				<tr>
					<th>Чупливо:</th>
					<td>
						<select name="fragile">
							<option value="no"  <?php selected( $settings['fragile_default'], 'no' ); ?>>Не</option>
							<option value="yes" <?php selected( $settings['fragile_default'], 'yes' ); ?>>Да</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Съботен разнос:</th>
					<td>
						<select name="saturday">
							<option value="no"  <?php selected( $settings['saturday_default'], 'no' ); ?>>Не</option>
							<option value="yes" <?php selected( $settings['saturday_default'], 'yes' ); ?>>Да</option>
						</select>
					</td>
				</tr>
			</table>

			<p class="speedy-actions">
				<button type="button" class="button button-primary" id="speedy-create-shipment">Създай пратка</button>
				<span id="speedy-mb-status"></span>
			</p>

			<?php if ( '' !== $last_error ) : ?>
				<p style="color:#b32d2e;">Последна грешка: <?php echo esc_html( $last_error ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_order_summary( $order, $shipment_id ) {
		$pickup_date = (string) $order->get_meta( Speedy_Shipment_Manager::META_PICKUP_DATE );
		$paper_size  = (string) Speedy_Settings::get( 'paper_size', 'A6' );
		$tomorrow    = wp_date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );
		$tracking    = sprintf( 'https://www.speedy.bg/bg/track-shipment?shipmentNumber=%s', rawurlencode( $shipment_id ) );
		?>
		<div id="speedy-shipment-box" class="speedy-shipment-summary" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<table class="speedy-grid">
				<tr>
					<th>Пратка номер:</th>
					<td><strong><?php echo esc_html( $shipment_id ); ?></strong></td>
				</tr>
				<tr>
					<th>Товарителница размер A4:</th>
					<td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'waybill', 'A4' ) ); ?>"><?php echo esc_html( $shipment_id ); ?> A4</a></td>
				</tr>
				<tr>
					<th>Товарителница размер A6:</th>
					<td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'waybill', 'A6' ) ); ?>"><?php echo esc_html( $shipment_id ); ?> A6</a></td>
				</tr>
				<tr>
					<th>Товарителница размер A4 (4xA6):</th>
					<td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'waybill', 'A4_4xA6' ) ); ?>"><?php echo esc_html( $shipment_id ); ?> A4_4xA6</a></td>
				</tr>
				<tr>
					<th>Пътен лист (voucher):</th>
					<td><a target="_blank" href="<?php echo esc_url( $this->build_print_url( $order, 'voucher' ) ); ?>"><?php echo esc_html( $shipment_id ); ?></a></td>
				</tr>
				<tr>
					<th>Дата на получаване:</th>
					<td><?php echo esc_html( $pickup_date ?: '—' ); ?></td>
				</tr>
				<tr>
					<th>Проследяване на пратка:</th>
					<td><a target="_blank" href="<?php echo esc_url( $tracking ); ?>">проследяване</a></td>
				</tr>
			</table>

			<p class="speedy-actions">
				<button type="button" class="button button-link-delete" id="speedy-cancel-shipment">Изтрий пратка</button>
				<button type="button" class="button" id="speedy-request-pickup">Заяви куриер</button>
				<label style="margin-left:12px;">Дата на заявка на куриер:
					<input type="date" id="speedy-pickup-date" value="<?php echo esc_attr( $tomorrow ); ?>" />
				</label>
				<label>час:
					<select id="speedy-pickup-hour">
						<?php for ( $h = 9; $h <= 18; $h++ ) : ?>
							<option value="<?php echo (int) $h; ?>" <?php selected( $h, 16 ); ?>><?php echo (int) $h; ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<span id="speedy-mb-status" style="margin-left:12px;"></span>
			</p>
		</div>
		<?php
	}

	public function ajax_create_shipment() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Поръчката не е намерена.' ) );
		}

		$post = wp_unslash( $_POST );

		$overrides = array(
			'sender_type'           => isset( $post['sender_type'] ) ? sanitize_text_field( $post['sender_type'] ) : null,
			'sender_client_id'      => isset( $post['sender_client_id'] ) ? (int) $post['sender_client_id'] : null,
			'sender_office_id'      => isset( $post['sender_office_id'] ) ? (int) $post['sender_office_id'] : null,
			'recipient_name'        => isset( $post['contact_name'] ) ? sanitize_text_field( $post['contact_name'] ) : null,
			'recipient_phone'       => isset( $post['contact_phone'] ) ? sanitize_text_field( $post['contact_phone'] ) : null,
			'recipient_email'       => isset( $post['contact_email'] ) ? sanitize_email( $post['contact_email'] ) : null,
			'payer_courier'         => isset( $post['payer_courier'] ) ? strtoupper( sanitize_text_field( $post['payer_courier'] ) ) : null,
			'pickup_date'           => isset( $post['pickup_date'] ) ? sanitize_text_field( $post['pickup_date'] ) : null,
			'parcels'               => isset( $post['parcels'] ) ? max( 1, (int) $post['parcels'] ) : null,
			'parcel_rows'           => isset( $post['parcel_rows'] ) && is_array( $post['parcel_rows'] ) ? $post['parcel_rows'] : null,
			'pack'                  => isset( $post['pack'] ) ? sanitize_text_field( $post['pack'] ) : null,
			'contents'              => isset( $post['contents'] ) ? sanitize_text_field( $post['contents'] ) : null,
			'note'                  => isset( $post['note'] ) ? sanitize_text_field( $post['note'] ) : null,
			'cod_on'                => isset( $post['cod_on'] ) && 'yes' === $post['cod_on'] ? 'yes' : 'no',
			'cod_type'              => isset( $post['cod_type'] ) ? sanitize_text_field( $post['cod_type'] ) : null,
			'cod_amount'            => isset( $post['cod_amount'] ) ? (float) $post['cod_amount'] : null,
			'declared_value_on'     => isset( $post['declared_value_on'] ) ? sanitize_text_field( $post['declared_value_on'] ) : null,
			'declared_value_amount' => isset( $post['declared_value_amount'] ) ? (float) $post['declared_value_amount'] : null,
			'fragile'               => isset( $post['fragile'] ) ? sanitize_text_field( $post['fragile'] ) : null,
			'saturday'              => isset( $post['saturday'] ) ? sanitize_text_field( $post['saturday'] ) : null,
			'opp_on'                => isset( $post['opp_on'] ) ? sanitize_text_field( $post['opp_on'] ) : null,
		);

		// Drop nulls so build_payload falls back to defaults.
		$overrides = array_filter(
			$overrides,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		// Compute weight from per-parcel rows if available.
		if ( ! empty( $overrides['parcel_rows'] ) && is_array( $overrides['parcel_rows'] ) ) {
			$total = 0.0;

			foreach ( $overrides['parcel_rows'] as $row ) {
				if ( isset( $row['weight'] ) ) {
					$total += (float) $row['weight'];
				}
			}

			if ( $total > 0 ) {
				$overrides['weight'] = $total;
			}
		}

		$manager = new Speedy_Shipment_Manager();
		$result  = $manager->generate_for_order( $order, $overrides );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'     => sprintf( 'Товарителница №%s беше генерирана.', $result['shipment_id'] ),
				'shipment_id' => $result['shipment_id'],
			)
		);
	}

	public function ajax_cancel_shipment() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Поръчката не е намерена.' ) );
		}

		$comment = isset( $_POST['comment'] ) ? sanitize_text_field( wp_unslash( $_POST['comment'] ) ) : 'Отказана от търговеца';

		$manager = new Speedy_Shipment_Manager();
		$result  = $manager->cancel( $order, $comment );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Товарителницата беше изтрита.' ) );
	}

	public function ajax_request_pickup() {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Поръчката не е намерена.' ) );
		}

		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$hour = isset( $_POST['hour'] ) ? (int) $_POST['hour'] : 0;

		$manager = new Speedy_Shipment_Manager();
		$result  = $manager->request_pickup( $order, $date, $hour );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Куриерът е заявен успешно.' ) );
	}

	public function handle_print_waybill() {
		$this->stream_print_response( 'waybill' );
	}

	public function handle_print_voucher() {
		$this->stream_print_response( 'voucher' );
	}

	private function stream_print_response( $type ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'speedy_print_' . $type . '_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_die( esc_html__( 'Поръчката не е намерена.', 'sameday-woocommerce-bg' ) );
		}

		$manager = new Speedy_Shipment_Manager();

		if ( 'voucher' === $type ) {
			$pdf = $manager->print_voucher( $order );
		} else {
			$paper_size = isset( $_GET['paper_size'] ) ? sanitize_text_field( wp_unslash( $_GET['paper_size'] ) ) : '';
			$pdf        = $manager->print_waybill( $order, $paper_size );
		}

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ) );
		}

		$shipment_id = (string) $order->get_meta( Speedy_Shipment_Manager::META_SHIPMENT_ID );
		$filename    = sprintf( 'speedy-%s-%s.pdf', $type, $shipment_id ?: $order_id );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	private function build_print_url( $order, $type, $paper_size = '' ) {
		$args = array(
			'action'   => 'speedy_print_' . $type,
			'order_id' => $order->get_id(),
			'_wpnonce' => wp_create_nonce( 'speedy_print_' . $type . '_' . $order->get_id() ),
		);

		if ( '' !== $paper_size ) {
			$args['paper_size'] = $paper_size;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	private function render_service_select( $name, $current, $services ) {
		$current = (string) $current;
		?>
		<select
			name="speedy[<?php echo esc_attr( $name ); ?>]"
			id="<?php echo esc_attr( 'speedy_' . $name ); ?>"
			class="speedy-service-select"
			data-current="<?php echo esc_attr( $current ); ?>"
			style="min-width:320px;"
		>
			<option value="">— Изберете услуга —</option>
			<?php if ( '' !== $current && ! $this->service_in_list( $current, $services ) ) : ?>
				<option value="<?php echo esc_attr( $current ); ?>" selected="selected">#<?php echo esc_html( $current ); ?> (запазен)</option>
			<?php endif; ?>
			<?php foreach ( $services as $service ) : ?>
				<option value="<?php echo esc_attr( (string) $service['id'] ); ?>" <?php selected( $current, (string) $service['id'] ); ?>>
					<?php echo esc_html( '#' . $service['id'] . ' — ' . $service['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function service_in_list( $id, $services ) {
		foreach ( (array) $services as $service ) {
			if ( isset( $service['id'] ) && (string) $service['id'] === (string) $id ) {
				return true;
			}
		}

		return false;
	}

	private function office_in_list( $offices, $id ) {
		foreach ( (array) $offices as $office ) {
			if ( isset( $office['id'] ) && (string) $office['id'] === (string) $id ) {
				return true;
			}
		}

		return false;
	}

	private function contract_has_id( $contract, $id ) {
		foreach ( (array) $contract as $client ) {
			if ( isset( $client['id'] ) && (string) $client['id'] === (string) $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build list of sender candidates (own client + contract clients).
	 *
	 * Speedy's `/client/contract` only returns OTHER clients under the same
	 * contract, so for single-entity merchants we need to surface their own
	 * client record from the profile as a sender option too.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_sender_client_options() {
		$profile  = Speedy_Settings::get_profile();
		$contract = isset( $profile['contract_clients'] ) && is_array( $profile['contract_clients'] ) ? $profile['contract_clients'] : array();
		$client   = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();

		$candidates = array();
		$seen       = array();

		$own_id = isset( $profile['client_id'] ) ? (int) $profile['client_id'] : 0;

		if ( $own_id > 0 ) {
			$own_name  = '';
			$candidates_keys = array( 'partnerName', 'companyName', 'clientName', 'name' );

			foreach ( $candidates_keys as $key ) {
				if ( ! empty( $client[ $key ] ) ) {
					$own_name = (string) $client[ $key ];
					break;
				}
			}

			if ( '' === $own_name ) {
				$own_name = 'Моят профил';
			}

			$candidates[] = array(
				'id'   => $own_id,
				'name' => $own_name . ' (моят профил)',
			);
			$seen[ $own_id ] = true;
		}

		foreach ( $contract as $contract_client ) {
			if ( ! is_array( $contract_client ) ) {
				continue;
			}

			$id = isset( $contract_client['id'] ) ? (int) $contract_client['id'] : 0;

			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}

			$candidates[] = array(
				'id'   => $id,
				'name' => isset( $contract_client['name'] ) ? (string) $contract_client['name'] : ( '#' . $id ),
			);

			$seen[ $id ] = true;
		}

		return $candidates;
	}

	private function render_payer_select( $name, $current ) {
		$choices = array(
			'SENDER'      => 'Изпращач',
			'RECIPIENT'   => 'Получател',
			'THIRD_PARTY' => 'Трета страна',
		);
		?>
		<select name="speedy[<?php echo esc_attr( $name ); ?>]">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function compose_default_contents( $order, $fallback ) {
		$names = array();

		foreach ( $order->get_items() as $item ) {
			if ( method_exists( $item, 'get_name' ) ) {
				$names[] = $item->get_name();
			}
		}

		if ( empty( $names ) ) {
			return (string) $fallback;
		}

		$joined = implode( ', ', $names );

		return mb_strlen( $joined ) > 60 ? mb_substr( $joined, 0, 57 ) . '...' : $joined;
	}

	private function extract_client_name( $client ) {
		if ( ! is_array( $client ) ) {
			return '—';
		}

		$candidates = array( 'clientName', 'name', 'companyName' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $client[ $key ] ) ) {
				return esc_html( (string) $client[ $key ] );
			}
		}

		return '—';
	}

	private function set_notice( $type, $message ) {
		set_transient( 'speedy_admin_notice', array( 'type' => $type, 'message' => $message ), 30 );
	}

	private function consume_notice() {
		$notice = get_transient( 'speedy_admin_notice' );

		if ( $notice ) {
			delete_transient( 'speedy_admin_notice' );
		}

		return is_array( $notice ) ? $notice : null;
	}

	private function is_order_edit_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		$candidates = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$candidates[] = wc_get_page_screen_id( 'shop-order' );
		}

		return in_array( $screen->id, array_filter( array_unique( $candidates ) ), true );
	}
}
