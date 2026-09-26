<?php
/**
 * Admin-specific functionality.
 *
 * @package Sameday_Woocommerce_Bg
 */

/**
 * Admin functionality.
 */
class Sameday_Woocommerce_Bg_Admin {

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
	 * Settings option name.
	 *
	 * @var string
	 */
	private $settings_option_name = 'sameday_woocommerce_bg_settings';

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
	 * Enqueue admin styles.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL . 'assets/css/admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( $this->plugin_name, SAMEDAY_WOOCOMMERCE_BG_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $this->version, false );
	}

	/**
	 * Register admin menu items.
	 *
	 * @return void
	 */
	public function add_plugin_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Настройки на Sameday', 'sameday-woocommerce-bg' ),
			__( 'Sameday', 'sameday-woocommerce-bg' ),
			'manage_woocommerce',
			$this->plugin_name,
			array( $this, 'display_plugin_setup_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'EasyBox автомати', 'sameday-woocommerce-bg' ),
			__( 'EasyBox Локации', 'sameday-woocommerce-bg' ),
			'manage_woocommerce',
			$this->plugin_name . '-locations',
			array( $this, 'display_locations_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Speedy офиси и АПС', 'sameday-woocommerce-bg' ),
			__( 'Speedy Локации', 'sameday-woocommerce-bg' ),
			'manage_woocommerce',
			$this->plugin_name . '-speedy-locations',
			array( $this, 'display_speedy_locations_page' )
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = array(
			'<a href="' . admin_url( 'admin.php?page=' . $this->plugin_name ) . '">' . __( 'Настройки', 'sameday-woocommerce-bg' ) . '</a>',
		);

		return array_merge( $settings_link, $links );
	}

	/**
	 * Render main plugin settings page.
	 *
	 * @return void
	 */
	public function display_plugin_setup_page() {
		$settings    = sameday_get_settings();
		$meta        = sameday_get_location_sync_meta();
		$speedy_meta = sameday_get_speedy_location_sync_meta();
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Плъгинът управлява избора и цената на доставката директно от checkout-а. Не е нужно да добавяте методи в WooCommerce > Доставка.', 'sameday-woocommerce-bg' ); ?></p>
			<?php $this->render_sync_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( $this->plugin_name ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Активирай плъгина', 'sameday-woocommerce-bg' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $this->settings_option_name ); ?>[enabled]" value="yes" <?php checked( $settings['enabled'], 'yes' ); ?> />
									<?php esc_html_e( 'Показвай избор на доставка в checkout и изчислявай цената автоматично.', 'sameday-woocommerce-bg' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Замени WooCommerce доставката', 'sameday-woocommerce-bg' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $this->settings_option_name ); ?>[replace_wc_shipping]" value="yes" <?php checked( $settings['replace_wc_shipping'], 'yes' ); ?> />
									<?php esc_html_e( 'Скрий стандартните WooCommerce shipping methods и добавяй цената като ред "Доставка" в общата сума.', 'sameday-woocommerce-bg' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Когато е активно, не разчитате на "Shipment 1" и на методи по зони.', 'sameday-woocommerce-bg' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Покажи куриери', 'sameday-woocommerce-bg' ); ?></th>
							<td>
								<label style="display:block;margin-bottom:8px;">
									<input type="checkbox" name="<?php echo esc_attr( $this->settings_option_name ); ?>[enable_sameday_provider]" value="yes" <?php checked( $settings['enable_sameday_provider'], 'yes' ); ?> />
									<?php esc_html_e( 'Sameday', 'sameday-woocommerce-bg' ); ?>
								</label>
								<label style="display:block;margin-bottom:8px;">
									<input type="checkbox" name="<?php echo esc_attr( $this->settings_option_name ); ?>[enable_speedy_provider]" value="yes" <?php checked( $settings['enable_speedy_provider'], 'yes' ); ?> />
									<?php esc_html_e( 'Speedy', 'sameday-woocommerce-bg' ); ?>
								</label>
								<label style="display:block;">
									<input type="checkbox" name="<?php echo esc_attr( $this->settings_option_name ); ?>[enable_a1post_provider]" value="yes" <?php checked( $settings['enable_a1post_provider'], 'yes' ); ?> />
									<?php esc_html_e( 'A1POST (международни доставки)', 'sameday-woocommerce-bg' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Sameday и Speedy се показват само за адреси в България. A1POST се показва само за адреси извън България.', 'sameday-woocommerce-bg' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Безплатна доставка над определена сума', 'sameday-woocommerce-bg' ); ?></th>
							<td>
								<label style="display:block;margin-bottom:12px;">
									<input type="checkbox" name="<?php echo esc_attr( $this->settings_option_name ); ?>[card_free_shipping_enabled]" value="yes" <?php checked( $settings['card_free_shipping_enabled'], 'yes' ); ?> />
									<?php esc_html_e( 'Активирай правилото', 'sameday-woocommerce-bg' ); ?>
								</label>

								<label style="display:block;margin-bottom:12px;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Праг на поръчката', 'sameday-woocommerce-bg' ); ?></span>
									<input
										type="number"
										class="regular-text"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( $this->settings_option_name ); ?>[card_free_shipping_threshold]"
										value="<?php echo esc_attr( $settings['card_free_shipping_threshold'] ); ?>"
									/>
									<span class="description"><?php echo esc_html( $currency ); ?></span>
								</label>

								<label style="display:block;margin-bottom:12px;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'За кои доставки важи', 'sameday-woocommerce-bg' ); ?></span>
									<select name="<?php echo esc_attr( $this->settings_option_name ); ?>[card_free_shipping_scope]">
										<option value="all" <?php selected( $settings['card_free_shipping_scope'], 'all' ); ?>><?php esc_html_e( 'За всички видове доставка (вкл. до адрес)', 'sameday-woocommerce-bg' ); ?></option>
										<option value="pickup" <?php selected( $settings['card_free_shipping_scope'], 'pickup' ); ?>><?php esc_html_e( 'Само за офис / автомат / АПС', 'sameday-woocommerce-bg' ); ?></option>
									</select>
								</label>

								<label style="display:block;margin-bottom:12px;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'За кои методи на плащане важи', 'sameday-woocommerce-bg' ); ?></span>
									<select name="<?php echo esc_attr( $this->settings_option_name ); ?>[card_free_shipping_payment_scope]">
										<option value="card" <?php selected( $settings['card_free_shipping_payment_scope'], 'card' ); ?>><?php esc_html_e( 'Само при плащане с карта', 'sameday-woocommerce-bg' ); ?></option>
										<option value="all" <?php selected( $settings['card_free_shipping_payment_scope'], 'all' ); ?>><?php esc_html_e( 'За всички методи на плащане (вкл. наложен платеж)', 'sameday-woocommerce-bg' ); ?></option>
									</select>
								</label>

								<label style="display:block;margin-bottom:12px;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'За кои държави важи', 'sameday-woocommerce-bg' ); ?></span>
									<select name="<?php echo esc_attr( $this->settings_option_name ); ?>[free_shipping_country_scope]">
										<option value="domestic" <?php selected( $settings['free_shipping_country_scope'], 'domestic' ); ?>>
											<?php
											echo esc_html(
												sprintf(
													'Само за адреси в %s',
													implode( ', ', sameday_get_free_shipping_countries() )
												)
											);
											?>
										</option>
										<option value="all" <?php selected( $settings['free_shipping_country_scope'], 'all' ); ?>><?php esc_html_e( 'За всички държави', 'sameday-woocommerce-bg' ); ?></option>
									</select>
								</label>

								<fieldset style="margin-bottom:8px;">
									<legend style="margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Кои методи на плащане се броят за "карта"', 'sameday-woocommerce-bg' ); ?></legend>
									<?php $this->render_gateway_checkboxes( $settings['card_free_shipping_gateways'] ); ?>
								</fieldset>

								<p class="description">
									<?php esc_html_e( 'Ако не отметнете нито един метод, за карта се брои всеки метод, който не е наложен платеж или банков превод. Списъкът има значение само когато правилото важи само за карта.', 'sameday-woocommerce-bg' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Международните доставки с A1POST никога не са безплатни - те се таксуват по зоновата тарифа, независимо от сумата на поръчката и от настройките по-горе.', 'sameday-woocommerce-bg' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Праг за безплатна доставка', 'sameday-woocommerce-bg' ); ?></th>
							<td>
								<label style="display:block;margin-bottom:12px;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Sameday', 'sameday-woocommerce-bg' ); ?></span>
									<input
										type="number"
										class="regular-text"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( $this->settings_option_name ); ?>[free_shipping_threshold_sameday]"
										value="<?php echo esc_attr( $settings['free_shipping_threshold_sameday'] ); ?>"
									/>
								</label>
								<label style="display:block;margin-bottom:12px;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Speedy', 'sameday-woocommerce-bg' ); ?></span>
									<input
										type="number"
										class="regular-text"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( $this->settings_option_name ); ?>[free_shipping_threshold_speedy]"
										value="<?php echo esc_attr( $settings['free_shipping_threshold_speedy'] ); ?>"
									/>
								</label>
								<label style="display:block;">
									<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'A1POST', 'sameday-woocommerce-bg' ); ?></span>
									<input
										type="number"
										class="regular-text"
										min="0"
										step="0.01"
										name="<?php echo esc_attr( $this->settings_option_name ); ?>[free_shipping_threshold_a1post]"
										value="<?php echo esc_attr( $settings['free_shipping_threshold_a1post'] ); ?>"
									/>
								</label>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											'Оставете празно или 0, за да няма безплатна доставка. Този праг важи само за Sameday EasyBox, Speedy офис и Speedy АПС. Доставката до адрес остава платена, освен ако не важи правилото за плащане с карта по-горе. Стойностите са в %s.',
											$currency
										)
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Запази настройките', 'sameday-woocommerce-bg' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'EasyBox синхронизация', 'sameday-woocommerce-bg' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						'Последно синхронизирани автомати: %1$d. Населени места: %2$d.',
						(int) $meta['count'],
						(int) $meta['cities_count']
					)
				);
				?>
			</p>
			<p><?php echo esc_html( $this->format_sync_time( $meta['synced_at'] ) ); ?></p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->plugin_name . '-locations' ) ); ?>">
					<?php esc_html_e( 'Управлявай EasyBox списъка', 'sameday-woocommerce-bg' ); ?>
				</a>
			</p>

			<hr />

			<h2><?php esc_html_e( 'Speedy синхронизация', 'sameday-woocommerce-bg' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						'Последно синхронизирани градове: %1$d. Офиси: %2$d. АПС: %3$d.',
						(int) $speedy_meta['cities_count'],
						(int) $speedy_meta['offices_count'],
						(int) $speedy_meta['aps_count']
					)
				);
				?>
			</p>
			<p><?php echo esc_html( $this->format_sync_time( $speedy_meta['synced_at'] ) ); ?></p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->plugin_name . '-speedy-locations' ) ); ?>">
					<?php esc_html_e( 'Управлявай Speedy списъка', 'sameday-woocommerce-bg' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render EasyBox locations page.
	 *
	 * @return void
	 */
	public function display_locations_page() {
		$meta = sameday_get_location_sync_meta();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_sync_notice(); ?>
			<p><?php esc_html_e( 'Списъкът с EasyBox автомати се дърпа автоматично от официалния Sameday endpoint, използван от тяхната публична карта.', 'sameday-woocommerce-bg' ); ?></p>

			<table class="widefat striped" style="max-width:960px;margin-top:16px;">
				<tbody>
					<tr>
						<td style="width:220px;"><strong><?php esc_html_e( 'Източник', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><a href="<?php echo esc_url( $meta['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $meta['source_url'] ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Автомати', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( (string) (int) $meta['count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Населени места', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( (string) (int) $meta['cities_count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Последна синхронизация', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_sync_time( $meta['synced_at'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<?php wp_nonce_field( 'sameday_sync_easybox_locations' ); ?>
				<input type="hidden" name="action" value="sameday_sync_easybox_locations" />
				<?php submit_button( __( 'Синхронизирай сега', 'sameday-woocommerce-bg' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $meta['last_error'] ) ) : ?>
				<p class="description" style="margin-top:12px;color:#b32d2e;">
					<?php echo esc_html( $meta['last_error'] ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Speedy locations page.
	 *
	 * @return void
	 */
	public function display_speedy_locations_page() {
		$meta = sameday_get_speedy_location_sync_meta();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_sync_notice(); ?>
			<p><?php esc_html_e( 'Списъкът с офиси и АПС се тегли от Speedy API с една заявка, когато са въведени потребител и парола в Speedy настройките. Ако API не е достъпен, се използват публичните страници на Speedy, което е бавно и се чупи при промяна по сайта им.', 'sameday-woocommerce-bg' ); ?></p>

			<table class="widefat striped" style="max-width:960px;margin-top:16px;">
				<tbody>
					<tr>
						<td style="width:220px;"><strong><?php esc_html_e( 'Използван източник', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td>
							<?php
							if ( 'api' === $meta['source'] ) {
								esc_html_e( 'Speedy API', 'sameday-woocommerce-bg' );
							} elseif ( 'scrape' === $meta['source'] ) {
								esc_html_e( 'Публичен сайт на Speedy (резервен източник)', 'sameday-woocommerce-bg' );
							} else {
								esc_html_e( 'Още няма успешна синхронизация', 'sameday-woocommerce-bg' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Адрес на източника', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( $meta['source_url'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Градове', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( (string) (int) $meta['cities_count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Офиси', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( (string) (int) $meta['offices_count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'АПС', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( (string) (int) $meta['aps_count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Общо локации', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( (string) (int) $meta['locations_count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Последна синхронизация', 'sameday-woocommerce-bg' ); ?></strong></td>
						<td><?php echo esc_html( $this->format_sync_time( $meta['synced_at'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<?php wp_nonce_field( 'sameday_sync_speedy_locations' ); ?>
				<input type="hidden" name="action" value="sameday_sync_speedy_locations" />
				<?php submit_button( __( 'Синхронизирай Speedy сега', 'sameday-woocommerce-bg' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $meta['last_error'] ) ) : ?>
				<p class="description" style="margin-top:12px;color:#b32d2e;">
					<?php echo esc_html( $meta['last_error'] ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function options_update() {
		register_setting( $this->plugin_name, $this->settings_option_name, array( $this, 'validate' ) );
	}

	/**
	 * Validate plugin settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function validate( $input ) {
		return array(
			'enabled'                 => ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no',
			'replace_wc_shipping'     => ( isset( $input['replace_wc_shipping'] ) && 'yes' === $input['replace_wc_shipping'] ) ? 'yes' : 'no',
			'enable_sameday_provider' => ( isset( $input['enable_sameday_provider'] ) && 'yes' === $input['enable_sameday_provider'] ) ? 'yes' : 'no',
			'enable_speedy_provider'  => ( isset( $input['enable_speedy_provider'] ) && 'yes' === $input['enable_speedy_provider'] ) ? 'yes' : 'no',
			'enable_a1post_provider'  => ( isset( $input['enable_a1post_provider'] ) && 'yes' === $input['enable_a1post_provider'] ) ? 'yes' : 'no',
			'free_shipping_threshold_sameday' => $this->sanitize_price_threshold( isset( $input['free_shipping_threshold_sameday'] ) ? $input['free_shipping_threshold_sameday'] : '' ),
			'free_shipping_threshold_speedy'  => $this->sanitize_price_threshold( isset( $input['free_shipping_threshold_speedy'] ) ? $input['free_shipping_threshold_speedy'] : '' ),
			'free_shipping_threshold_a1post'  => $this->sanitize_price_threshold( isset( $input['free_shipping_threshold_a1post'] ) ? $input['free_shipping_threshold_a1post'] : '' ),
			'card_free_shipping_enabled'   => ( isset( $input['card_free_shipping_enabled'] ) && 'yes' === $input['card_free_shipping_enabled'] ) ? 'yes' : 'no',
			'card_free_shipping_threshold' => $this->sanitize_price_threshold( isset( $input['card_free_shipping_threshold'] ) ? $input['card_free_shipping_threshold'] : '' ),
			'card_free_shipping_scope'     => ( isset( $input['card_free_shipping_scope'] ) && 'pickup' === $input['card_free_shipping_scope'] ) ? 'pickup' : 'all',
			'card_free_shipping_gateways'  => $this->sanitize_gateway_list( isset( $input['card_free_shipping_gateways'] ) ? $input['card_free_shipping_gateways'] : array() ),
			'card_free_shipping_payment_scope' => ( isset( $input['card_free_shipping_payment_scope'] ) && 'all' === $input['card_free_shipping_payment_scope'] ) ? 'all' : 'card',
			'free_shipping_country_scope'      => ( isset( $input['free_shipping_country_scope'] ) && 'all' === $input['free_shipping_country_scope'] ) ? 'all' : 'domestic',
		);
	}

	/**
	 * Sanitize the list of gateway ids that count as card payments.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private function sanitize_gateway_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $gateway_id ) {
			$gateway_id = sanitize_text_field( wp_unslash( (string) $gateway_id ) );

			if ( '' !== $gateway_id ) {
				$clean[ $gateway_id ] = $gateway_id;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Render the payment gateway checkboxes for the card rule.
	 *
	 * @param mixed $selected Currently selected gateway ids.
	 * @return void
	 */
	private function render_gateway_checkboxes( $selected ) {
		$selected = is_array( $selected ) ? array_map( 'strval', $selected ) : array();
		$gateways = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
		}

		if ( empty( $gateways ) ) {
			echo '<p class="description">' . esc_html__( 'Няма намерени методи на плащане.', 'sameday-woocommerce-bg' ) . '</p>';

			return;
		}

		foreach ( $gateways as $gateway_id => $gateway ) {
			$gateway_id = (string) $gateway_id;
			$title      = method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : $gateway_id;
			$is_enabled = isset( $gateway->enabled ) && 'yes' === $gateway->enabled;
			?>
			<label style="display:block;margin-bottom:4px;">
				<input
					type="checkbox"
					name="<?php echo esc_attr( $this->settings_option_name ); ?>[card_free_shipping_gateways][]"
					value="<?php echo esc_attr( $gateway_id ); ?>"
					<?php checked( in_array( $gateway_id, $selected, true ) ); ?>
				/>
				<?php echo esc_html( $title ); ?>
				<code><?php echo esc_html( $gateway_id ); ?></code>
				<?php if ( ! $is_enabled ) : ?>
					<span class="description">(<?php esc_html_e( 'изключен', 'sameday-woocommerce-bg' ); ?>)</span>
				<?php endif; ?>
			</label>
			<?php
		}
	}

	/**
	 * Sanitize one free shipping threshold.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_price_threshold( $value ) {
		$value = is_scalar( $value ) ? trim( wp_unslash( (string) $value ) ) : '';

		if ( '' === $value ) {
			return '';
		}

		$normalized = wc_format_decimal( $value, wc_get_price_decimals() );

		if ( '' === $normalized ) {
			return '';
		}

		return (float) $normalized > 0 ? $normalized : '';
	}

	/**
	 * Trigger official EasyBox sync from admin.
	 *
	 * @return void
	 */
	public function sync_easybox_locations() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'sameday_sync_easybox_locations' );

		$repository = new Sameday_Location_Repository();
		$result     = $repository->sync_locations();
		$redirect   = admin_url( 'admin.php?page=' . $this->plugin_name . '-locations' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'sameday_sync' => 'error',
					'target'       => 'easybox',
					'message'      => rawurlencode( $result->get_error_message() ),
				),
				$redirect
			);
		} else {
			$redirect = add_query_arg(
				array(
					'sameday_sync' => 'success',
					'target'       => 'easybox',
					'count'        => count( $result ),
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Trigger official Speedy sync from admin.
	 *
	 * @return void
	 */
	public function sync_speedy_locations() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'sameday_sync_speedy_locations' );

		$repository = new Speedy_Location_Repository();
		$result     = $repository->sync_locations();
		$redirect   = admin_url( 'admin.php?page=' . $this->plugin_name . '-speedy-locations' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'sameday_sync' => 'error',
					'target'       => 'speedy',
					'message'      => rawurlencode( $result->get_error_message() ),
				),
				$redirect
			);
		} else {
			$redirect = add_query_arg(
				array(
					'sameday_sync'  => 'success',
					'target'        => 'speedy',
					'cities_count'  => isset( $result['cities_count'] ) ? (int) $result['cities_count'] : 0,
					'offices_count' => isset( $result['offices_count'] ) ? (int) $result['offices_count'] : 0,
					'aps_count'     => isset( $result['aps_count'] ) ? (int) $result['aps_count'] : 0,
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Save location placeholder handler kept for backward compatibility.
	 *
	 * @return void
	 */
	public function save_location() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->plugin_name . '-locations' ) );
		exit;
	}

	/**
	 * Delete location placeholder handler kept for backward compatibility.
	 *
	 * @return void
	 */
	public function delete_location() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->plugin_name . '-locations' ) );
		exit;
	}

	/**
	 * Show WooCommerce missing notice.
	 *
	 * @return void
	 */
	public function wc_missing_notice() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				esc_html__( 'Sameday WooCommerce България изисква WooCommerce. %sАктивирайте WooCommerce%s', 'sameday-woocommerce-bg' ),
				'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">',
				'</a>'
			);
			echo '</p></div>';
		}
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
	 * Render sync notice if present.
	 *
	 * @return void
	 */
	private function render_sync_notice() {
		if ( empty( $_GET['sameday_sync'] ) ) {
			return;
		}

		$type   = sanitize_text_field( wp_unslash( $_GET['sameday_sync'] ) );
		$target = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : 'easybox';

		if ( 'success' === $type ) {
			if ( 'speedy' === $target ) {
				$cities_count  = isset( $_GET['cities_count'] ) ? absint( wp_unslash( $_GET['cities_count'] ) ) : 0;
				$offices_count = isset( $_GET['offices_count'] ) ? absint( wp_unslash( $_GET['offices_count'] ) ) : 0;
				$aps_count     = isset( $_GET['aps_count'] ) ? absint( wp_unslash( $_GET['aps_count'] ) ) : 0;
				echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( 'Speedy списъкът е синхронизиран успешно. Градове: %1$d. Офиси: %2$d. АПС: %3$d.', $cities_count, $offices_count, $aps_count ) ) . '</p></div>';
				return;
			}

			$count = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( 'EasyBox списъкът е синхронизиран успешно. Обновени автомати: %d.', $count ) ) . '</p></div>';
			return;
		}

		if ( 'error' === $type ) {
			$message = isset( $_GET['message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['message'] ) ) ) : 'Неуспешна синхронизация.';
			echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Format sync timestamp.
	 *
	 * @param int|string $timestamp Sync timestamp.
	 * @return string
	 */
	private function format_sync_time( $timestamp ) {
		$timestamp = (int) $timestamp;

		if ( $timestamp <= 0 ) {
			return 'Все още няма успешна синхронизация.';
		}

		return sprintf(
			'Последна синхронизация: %s',
			wp_date( 'd.m.Y H:i', $timestamp )
		);
	}
}
