<?php
/**
 * Plugin bootstrap.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class: loads modules once WooCommerce is available.
 */
final class Tax_ID_Checkout {

	/**
	 * Singleton instance.
	 *
	 * @var Tax_ID_Checkout|null
	 */
	private static $instance = null;

	/**
	 * Get (and boot on first call) the plugin instance.
	 *
	 * @return Tax_ID_Checkout
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_translations' ), 0 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-settings.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-validator.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-countries.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-data.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-classic.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-blocks.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-display.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-postcode.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-assets.php';
		require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout-admin.php';

		Tax_ID_Checkout_Settings::init();
		Tax_ID_Checkout_Classic::init();
		Tax_ID_Checkout_Blocks::init();
		Tax_ID_Checkout_Display::init();
		Tax_ID_Checkout_Postcode::init();
		Tax_ID_Checkout_Assets::init();
		Tax_ID_Checkout_Admin::init();

		do_action( 'tax_id_checkout_loaded' );
	}

	/**
	 * Load bundled translations. Any regional Spanish/Portuguese locale falls back to es_ES/pt_BR.
	 */
	public function load_translations() {
		$locale = determine_locale();
		$file   = TAX_ID_CHECKOUT_DIR . 'languages/cedulio-' . $locale . '.mo';

		if ( ! file_exists( $file ) ) {
			$fallbacks = array(
				'es' => 'es_ES',
				'pt' => 'pt_BR',
			);
			$language  = substr( $locale, 0, 2 );
			if ( isset( $fallbacks[ $language ] ) ) {
				$file = TAX_ID_CHECKOUT_DIR . 'languages/cedulio-' . $fallbacks[ $language ] . '.mo';
			}
		}

		if ( file_exists( $file ) ) {
			load_textdomain( 'cedulio', $file );
		}
	}

	/**
	 * Whether the Pro features are unlocked.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return class_exists( 'Tax_ID_Checkout_Pro' ) && Tax_ID_Checkout_Pro::is_active();
	}

	/**
	 * Upgrade URL for the Pro version, or empty string when Freemius is not configured.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		return function_exists( 'tax_id_checkout_fs' ) ? tax_id_checkout_fs()->get_upgrade_url() : '';
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public function notice_missing_woocommerce() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Cedulio needs WooCommerce to be installed and active.', 'cedulio' ) . '</p></div>';
	}
}
