<?php
/**
 * Plugin Name:       Tax ID Checkout
 * Plugin URI:        https://wordpress.org/plugins/tax-id-checkout/
 * Description:       Ask for the right tax ID in every country (CPF/CNPJ, RUC, CUIT, RUT, RFC, NIF, VAT number…) with real check-digit validation, plus house number, neighborhood and postcode autofill. Works with the block and the classic checkout.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Marcos Arellano
 * Author URI:        https://marcos-arellano.vercel.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tax-id-checkout
 * Domain Path:       /languages
 * WC requires at least: 9.9
 * WC tested up to:   11.2
 *
 * @fs_premium_only /includes/pro/
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

define( 'TAX_ID_CHECKOUT_VERSION', '1.0.0' );
define( 'TAX_ID_CHECKOUT_FILE', __FILE__ );
define( 'TAX_ID_CHECKOUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAX_ID_CHECKOUT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Freemius product credentials (Freemius Developer Dashboard → your product → Settings → Keys).
 * The public key is meant to be public; never put the secret key here.
 * While these are empty the plugin runs without Freemius (free features only).
 */
define( 'TAX_ID_CHECKOUT_FS_ID', '43688' );
define( 'TAX_ID_CHECKOUT_FS_PUBLIC_KEY', 'pk_b495651d010e7d6d7881d91b0e486' );

/**
 * Whether the Freemius credentials have been filled in.
 *
 * @return bool
 */
function tax_id_checkout_fs_is_configured() {
	return '' !== TAX_ID_CHECKOUT_FS_ID && '' !== TAX_ID_CHECKOUT_FS_PUBLIC_KEY;
}

if ( tax_id_checkout_fs_is_configured() && ! function_exists( 'tax_id_checkout_fs' ) ) {
	/**
	 * Freemius SDK instance (licensing, checkout and updates of the Pro version).
	 *
	 * @return Freemius
	 */
	function tax_id_checkout_fs() {
		global $tax_id_checkout_fs;

		if ( ! isset( $tax_id_checkout_fs ) ) {
			require_once TAX_ID_CHECKOUT_DIR . 'vendor/freemius/start.php';

			$tax_id_checkout_fs = fs_dynamic_init(
				array(
					'id'                  => TAX_ID_CHECKOUT_FS_ID,
					'slug'                => 'tax-id-checkout',
					'premium_slug'        => 'tax-id-checkout-pro',
					'type'                => 'plugin',
					'public_key'          => TAX_ID_CHECKOUT_FS_PUBLIC_KEY,
					'is_premium'          => false,
					'premium_suffix'      => 'Pro',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					'menu'                => array(
						'slug'       => 'tax-id-checkout',
						'first-path' => 'admin.php?page=tax-id-checkout',
						'contact'    => false,
						'support'    => false,
						'parent'     => array(
							'slug' => 'woocommerce',
						),
					),
				)
			);
		}

		return $tax_id_checkout_fs;
	}

	tax_id_checkout_fs();
	do_action( 'tax_id_checkout_fs_loaded' );
}

/**
 * Delete the plugin settings when the plugin is deleted. Order and customer data is kept.
 *
 * Runs from Freemius' uninstall hook (Freemius needs to own uninstall, so there is no uninstall.php).
 */
function tax_id_checkout_uninstall_cleanup() {
	delete_option( 'tax_id_checkout_settings' );
	delete_option( 'tax_id_checkout_imported' );
}

if ( function_exists( 'tax_id_checkout_fs' ) ) {
	tax_id_checkout_fs()->add_action( 'after_uninstall', 'tax_id_checkout_uninstall_cleanup' );
} else {
	register_uninstall_hook( TAX_ID_CHECKOUT_FILE, 'tax_id_checkout_uninstall_cleanup' );
}

require_once TAX_ID_CHECKOUT_DIR . 'includes/class-tax-id-checkout.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TAX_ID_CHECKOUT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TAX_ID_CHECKOUT_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Tax_ID_Checkout', 'instance' ) );
