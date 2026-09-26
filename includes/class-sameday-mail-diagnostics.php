<?php
/**
 * Order e-mail diagnostics.
 *
 * The plugin itself only appends the delivery block to WooCommerce e-mails, so
 * when confirmations stop arriving the cause is almost always outside of it:
 * a gateway that leaves the order pending, a disabled WooCommerce e-mail, or a
 * host that silently drops mail(). This screen collects those signals in one
 * place and can send a test message.
 *
 * @package Sameday_Woocommerce_Bg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mail diagnostics screen.
 */
class Sameday_Mail_Diagnostics {

	/**
	 * Option holding the recent outgoing mail log.
	 */
	const LOG_OPTION_KEY = 'sameday_mail_log';

	/**
	 * How many entries the log keeps.
	 */
	const LOG_LIMIT = 60;

	/**
	 * Collected wp_mail error, if any.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_post_sameday_send_test_email', array( $this, 'send_test_email' ) );
		add_action( 'admin_post_sameday_clear_mail_log', array( $this, 'clear_log' ) );

		// Recording every send is what turns "the customer says no e-mail
		// arrived" into an answer: either the shop never sent it, or it did and
		// the message was lost after it left WordPress.
		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
	}

	/**
	 * Record one successful send.
	 *
	 * @param array<string, mixed> $mail_data Mail data passed by WordPress.
	 * @return void
	 */
	public function log_success( $mail_data ) {
		$this->append_log_entry( 'sent', $mail_data, '' );
	}

	/**
	 * Record one failed send.
	 *
	 * @param WP_Error $error Failure reported by WordPress.
	 * @return void
	 */
	public function log_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$data = $error->get_error_data();

		$this->append_log_entry( 'failed', is_array( $data ) ? $data : array(), $error->get_error_message() );
	}

	/**
	 * Append one entry to the capped log.
	 *
	 * @param string               $status    sent|failed.
	 * @param array<string, mixed> $mail_data Mail data.
	 * @param string               $message   Failure message.
	 * @return void
	 */
	private function append_log_entry( $status, $mail_data, $message ) {
		$to = isset( $mail_data['to'] ) ? $mail_data['to'] : '';

		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'strval', $to ) );
		}

		$log = get_option( self::LOG_OPTION_KEY, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'status'  => 'failed' === $status ? 'failed' : 'sent',
				'to'      => sanitize_text_field( (string) $to ),
				'subject' => sanitize_text_field( (string) ( isset( $mail_data['subject'] ) ? $mail_data['subject'] : '' ) ),
				'message' => sanitize_text_field( (string) $message ),
			)
		);

		update_option( self::LOG_OPTION_KEY, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public function clear_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'sameday_clear_mail_log' );
		delete_option( self::LOG_OPTION_KEY );
		wp_safe_redirect( admin_url( 'admin.php?page=sameday-woocommerce-bg-mail' ) );
		exit;
	}

	/**
	 * Add the submenu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Диагностика на имейлите', 'sameday-woocommerce-bg' ),
			__( 'Имейл диагностика', 'sameday-woocommerce-bg' ),
			'manage_woocommerce',
			'sameday-woocommerce-bg-mail',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the test e-mail submission.
	 *
	 * @return void
	 */
	public function send_test_email() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права за това действие.', 'sameday-woocommerce-bg' ) );
		}

		check_admin_referer( 'sameday_send_test_email' );

		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		$redirect  = admin_url( 'admin.php?page=sameday-woocommerce-bg-mail' );

		if ( ! is_email( $recipient ) ) {
			wp_safe_redirect( add_query_arg( array( 'sameday_mail' => 'invalid' ), $redirect ) );
			exit;
		}

		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );

		$sent = wp_mail(
			$recipient,
			'Тестов имейл от ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			"Това е тестов имейл, изпратен от диагностиката на плъгина за доставки.\n\nАко го получавате, изходящата поща на сайта работи."
		);

		remove_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );

		$args = array( 'sameday_mail' => $sent ? 'sent' : 'failed' );

		if ( ! $sent && '' !== $this->last_error ) {
			$args['message'] = rawurlencode( $this->last_error );
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Store the wp_mail error message.
	 *
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	public function capture_mail_error( $error ) {
		if ( is_wp_error( $error ) ) {
			$this->last_error = $error->get_error_message();
		}
	}

	/**
	 * Render the diagnostics page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_notice(); ?>

			<p>
				<?php esc_html_e( 'Плъгинът за доставки не спира имейли - той само добавя блока с избраната доставка към писмата на WooCommerce. Таблиците по-долу показват най-честите причини поръчките да минават без потвърждение.', 'sameday-woocommerce-bg' ); ?>
			</p>

			<h2><?php esc_html_e( 'Изходяща поща', 'sameday-woocommerce-bg' ); ?></h2>
			<?php $this->render_mail_environment(); ?>

			<h2><?php esc_html_e( 'Имейли на WooCommerce', 'sameday-woocommerce-bg' ); ?></h2>
			<?php $this->render_wc_emails(); ?>

			<h2><?php esc_html_e( 'Последни поръчки', 'sameday-woocommerce-bg' ); ?></h2>
			<?php $this->render_recent_orders(); ?>

			<h2><?php esc_html_e( 'Изпратени имейли', 'sameday-woocommerce-bg' ); ?></h2>
			<?php $this->render_mail_log(); ?>

			<h2><?php esc_html_e( 'Тестов имейл', 'sameday-woocommerce-bg' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sameday_send_test_email' ); ?>
				<input type="hidden" name="action" value="sameday_send_test_email" />
				<p>
					<input
						type="email"
						name="recipient"
						class="regular-text"
						required
						value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
					/>
				</p>
				<?php submit_button( __( 'Изпрати тестов имейл', 'sameday-woocommerce-bg' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the outgoing mail environment table.
	 *
	 * @return void
	 */
	private function render_mail_environment() {
		$smtp_plugins = $this->get_active_smtp_plugins();
		$rows         = array(
			array(
				__( 'PHP mail() налична', 'sameday-woocommerce-bg' ),
				function_exists( 'mail' ) ? __( 'Да', 'sameday-woocommerce-bg' ) : __( 'Не - хостингът не позволява изпращане', 'sameday-woocommerce-bg' ),
			),
			array(
				__( 'SMTP плъгин', 'sameday-woocommerce-bg' ),
				empty( $smtp_plugins ) ? __( 'Няма активен - пощата се праща през сървъра, което често се маркира като спам или се блокира.', 'sameday-woocommerce-bg' ) : implode( ', ', $smtp_plugins ),
			),
			array(
				__( 'Подател по подразбиране (WordPress)', 'sameday-woocommerce-bg' ),
				'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			),
			array(
				__( 'Подател на WooCommerce', 'sameday-woocommerce-bg' ),
				(string) get_option( 'woocommerce_email_from_name' ) . ' <' . (string) get_option( 'woocommerce_email_from_address' ) . '>',
			),
			array(
				__( 'Администраторски имейл', 'sameday-woocommerce-bg' ),
				(string) get_option( 'admin_email' ),
			),
		);
		?>
		<table class="widefat striped" style="max-width:960px;">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td style="width:280px;"><strong><?php echo esc_html( $row[0] ); ?></strong></td>
						<td><?php echo esc_html( $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the WooCommerce customer e-mail status table.
	 *
	 * @return void
	 */
	private function render_wc_emails() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$watch  = array( 'WC_Email_New_Order', 'WC_Email_Customer_Processing_Order', 'WC_Email_Customer_On_Hold_Order', 'WC_Email_Customer_Completed_Order', 'WC_Email_Customer_Invoice' );
		?>
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Имейл', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Получател', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'sameday-woocommerce-bg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $watch as $email_class ) : ?>
					<?php if ( ! isset( $emails[ $email_class ] ) ) { continue; } ?>
					<?php $email = $emails[ $email_class ]; ?>
					<tr>
						<td><?php echo esc_html( $email->get_title() ); ?></td>
						<td><?php echo esc_html( $email->is_customer_email() ? __( 'Клиент', 'sameday-woocommerce-bg' ) : (string) $email->get_recipient() ); ?></td>
						<td>
							<?php if ( $email->is_enabled() ) : ?>
								<span style="color:#0b7a3b;"><?php esc_html_e( 'Активен', 'sameday-woocommerce-bg' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Изключен', 'sameday-woocommerce-bg' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'WooCommerce не изпраща потвърждение към клиента, докато поръчката стои в статус "Изчакващо плащане" или "Неуспешна". Ако плащането с карта не се потвърждава, поръчката остава в такъв статус и имейл не тръгва.', 'sameday-woocommerce-bg' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the last orders with their status and payment method.
	 *
	 * @return void
	 */
	private function render_recent_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 10,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			echo '<p>' . esc_html__( 'Няма поръчки.', 'sameday-woocommerce-bg' ) . '</p>';

			return;
		}

		$silent_statuses = array( 'pending', 'failed', 'cancelled' );
		?>
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Поръчка', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Дата', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Плащане', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Имейл до клиента', 'sameday-woocommerce-bg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php
					$status  = $order->get_status();
					$created = $order->get_date_created();
					$silent  = in_array( $status, $silent_statuses, true );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
						</td>
						<td><?php echo esc_html( $created ? $created->date_i18n( 'd.m.Y H:i' ) : '-' ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></td>
						<td><?php echo esc_html( $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method() ); ?></td>
						<td>
							<?php if ( $silent ) : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Не се изпраща за този статус', 'sameday-woocommerce-bg' ); ?></span>
							<?php else : ?>
								<span style="color:#0b7a3b;"><?php esc_html_e( 'Очаква се да е изпратен', 'sameday-woocommerce-bg' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the recorded outgoing mail.
	 *
	 * @return void
	 */
	private function render_mail_log() {
		$log = get_option( self::LOG_OPTION_KEY, array() );

		if ( ! is_array( $log ) || empty( $log ) ) {
			echo '<p>' . esc_html__( 'Още няма записани изпращания. Всеки имейл, който сайтът изпрати от сега нататък, ще се появи тук.', 'sameday-woocommerce-bg' ) . '</p>';

			return;
		}
		?>
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th style="width:150px;"><?php esc_html_e( 'Дата', 'sameday-woocommerce-bg' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Резултат', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Получател', 'sameday-woocommerce-bg' ); ?></th>
					<th><?php esc_html_e( 'Тема / грешка', 'sameday-woocommerce-bg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<?php
					$failed = isset( $entry['status'] ) && 'failed' === $entry['status'];
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $entry['time'] ) ); ?></td>
						<td>
							<?php if ( $failed ) : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Провалено', 'sameday-woocommerce-bg' ); ?></span>
							<?php else : ?>
								<span style="color:#0b7a3b;"><?php esc_html_e( 'Изпратено', 'sameday-woocommerce-bg' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $entry['to'] ); ?></td>
						<td>
							<?php echo esc_html( (string) $entry['subject'] ); ?>
							<?php if ( ! empty( $entry['message'] ) ) : ?>
								<br /><span style="color:#b32d2e;"><?php echo esc_html( (string) $entry['message'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( '"Изпратено" означава, че WordPress е предал писмото на пощенския сървър. Ако клиентът пак не го получава, проблемът е след този момент - спам филтър или отхвърляне от получаващия сървър - и се решава с SMTP плъгин и подписан домейн (SPF, DKIM, DMARC).', 'sameday-woocommerce-bg' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<?php wp_nonce_field( 'sameday_clear_mail_log' ); ?>
			<input type="hidden" name="action" value="sameday_clear_mail_log" />
			<?php submit_button( __( 'Изчисти дневника', 'sameday-woocommerce-bg' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Return the names of active SMTP plugins.
	 *
	 * @return array<int, string>
	 */
	private function get_active_smtp_plugins() {
		$known = array(
			'wp-mail-smtp/wp_mail_smtp.php'          => 'WP Mail SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'          => 'Easy WP SMTP',
			'post-smtp/postman-smtp.php'             => 'Post SMTP',
			'fluent-smtp/fluent-smtp.php'            => 'FluentSMTP',
			'wp-ses/wp-ses.php'                      => 'WP Offload SES',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
			'mailgun/mailgun.php'                    => 'Mailgun',
		);

		$active = array();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $known as $plugin_file => $label ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$active[] = $label;
			}
		}

		return $active;
	}

	/**
	 * Render the result notice of a test send.
	 *
	 * @return void
	 */
	private function render_notice() {
		if ( empty( $_GET['sameday_mail'] ) ) {
			return;
		}

		$result  = sanitize_text_field( wp_unslash( $_GET['sameday_mail'] ) );
		$message = isset( $_GET['message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['message'] ) ) ) : '';

		if ( 'sent' === $result ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Тестовият имейл беше подаден за изпращане. Проверете пощата, включително папка "Спам".', 'sameday-woocommerce-bg' ) . '</p></div>';

			return;
		}

		if ( 'invalid' === $result ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Невалиден имейл адрес.', 'sameday-woocommerce-bg' ) . '</p></div>';

			return;
		}

		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Изпращането се провали.', 'sameday-woocommerce-bg' );

		if ( '' !== $message ) {
			echo ' ' . esc_html( $message );
		}

		echo '</p></div>';
	}
}
