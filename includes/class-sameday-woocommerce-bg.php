<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Sameday_Woocommerce_Bg
 * @subpackage Sameday_Woocommerce_Bg/includes
 * @author     Sameday WooCommerce BG <contact@example.com>
 */
class Sameday_Woocommerce_Bg {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Sameday_Woocommerce_Bg_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'wc_missing_notice' ) );
			return;
		}

		if ( defined( 'SAMEDAY_WOOCOMMERCE_BG_VERSION' ) ) {
			$this->version = SAMEDAY_WOOCOMMERCE_BG_VERSION;
		} else {
			$this->version = '1.4.5';
		}
		$this->plugin_name = 'sameday-woocommerce-bg';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_woocommerce_hooks();
	}

	/**
	 * WooCommerce missing notice.
	 */
	public function wc_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php _e( 'Sameday WooCommerce България изисква WooCommerce да бъде инсталиран и активиран.', 'sameday-woocommerce-bg' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Sameday_Woocommerce_Bg_Loader. Orchestrates the hooks of the plugin.
	 * - Sameday_Woocommerce_Bg_i18n. Defines internationalization functionality.
	 * - Sameday_Woocommerce_Bg_Admin. Defines all hooks for the admin area.
	 * - Sameday_Woocommerce_Bg_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-woocommerce-bg-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-woocommerce-bg-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-woocommerce-bg-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-woocommerce-bg-public.php';

		/**
		 * The class responsible for checkout functionality.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-checkout.php';

		/**
		 * The class responsible for location management.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-location-repository.php';

		/**
		 * The class responsible for Speedy office and APS lookups.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-speedy-location-repository.php';

		/**
		 * Speedy API integration: client + settings + shipment workflow + admin.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-speedy-api-client.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-speedy-settings.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-speedy-shipment-manager.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-speedy-rate-cache.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-speedy-admin.php';

		/**
		 * A1POST international delivery: tariff + settings screen.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-a1post-api-client.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-a1post-tariff.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-a1post-shipment-manager.php';
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-a1post-admin.php';

		/**
		 * Order e-mail diagnostics screen.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-mail-diagnostics.php';

		/**
		 * The class responsible for price calculation.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-price-calculator.php';

		/**
		 * The class responsible for order meta data.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-order-meta.php';

		/**
		 * The class responsible for AJAX functionality.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/class-sameday-ajax.php';

		/**
		 * Helper functions.
		 */
		require_once SAMEDAY_WOOCOMMERCE_BG_PLUGIN_DIR . 'includes/helpers.php';

		$this->loader = new Sameday_Woocommerce_Bg_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Sameday_Woocommerce_Bg_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Sameday_Woocommerce_Bg_i18n();

		$this->loader->add_action( 'init', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Sameday_Woocommerce_Bg_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		// Add admin menu
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );

		// Add Settings link to the plugin
		$plugin_basename = SAMEDAY_WOOCOMMERCE_BG_PLUGIN_BASENAME;
		$this->loader->add_filter( 'plugin_action_links_' . $plugin_basename, $plugin_admin, 'add_action_links' );

		// Register settings
		$this->loader->add_action( 'admin_init', $plugin_admin, 'options_update' );

		// Handle location management
		$this->loader->add_action( 'admin_post_sameday_save_location', $plugin_admin, 'save_location' );
		$this->loader->add_action( 'admin_post_sameday_delete_location', $plugin_admin, 'delete_location' );
		$this->loader->add_action( 'admin_post_sameday_sync_easybox_locations', $plugin_admin, 'sync_easybox_locations' );
		$this->loader->add_action( 'admin_post_sameday_sync_speedy_locations', $plugin_admin, 'sync_speedy_locations' );

		// Check WooCommerce compatibility
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'wc_missing_notice' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Sameday_Woocommerce_Bg_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Register WooCommerce-specific hooks.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_woocommerce_hooks() {
		$checkout = new Sameday_Checkout();
		$this->loader->add_action( 'woocommerce_after_checkout_billing_form', $checkout, 'render_delivery_fields' );
		$this->loader->add_action( 'woocommerce_checkout_update_order_review', $checkout, 'update_delivery_session' );
		$this->loader->add_action( 'woocommerce_cart_calculate_fees', $checkout, 'add_delivery_fee', 100 );
		$this->loader->add_filter( 'woocommerce_cart_needs_shipping', $checkout, 'filter_cart_needs_shipping', 100 );
		$this->loader->add_action( 'woocommerce_checkout_process', $checkout, 'validate_delivery_selection' );
		$this->loader->add_action( 'woocommerce_checkout_update_order_meta', $checkout, 'save_order_meta' );

		new Sameday_Ajax();
		new Sameday_Order_Meta();

		$speedy_admin = new Speedy_Admin();
		$speedy_admin->register();

		$a1post_admin = new A1post_Admin();
		$a1post_admin->register();

		$mail_diagnostics = new Sameday_Mail_Diagnostics();
		$mail_diagnostics->register();

		Speedy_Rate_Cache::register_cron_handler();
		Speedy_Location_Repository::register_cron_handler();
		Sameday_Location_Repository::register_cron_handler();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Sameday_Woocommerce_Bg_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Load the shipping method classes when WooCommerce is ready for them.
	 *
	 * @since    1.0.0
	 */
	public function load_shipping_method_dependencies() {}

	/**
	 * Register Sameday shipping methods with WooCommerce.
	 *
	 * @since    1.0.0
	 * @param    array $methods Registered shipping methods.
	 * @return   array
	 */
	public function register_shipping_methods( $methods ) { return $methods; }

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		if ( null === $this->loader ) {
			return;
		}
		$this->loader->run();
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
}


}
