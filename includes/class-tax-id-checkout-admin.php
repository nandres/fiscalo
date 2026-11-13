<?php
/**
 * Admin helpers: plugin links, notices and Pro box.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI glue.
 */
class Tax_ID_Checkout_Admin {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_filter( 'plugin_action_links_' . plugin_basename( TAX_ID_CHECKOUT_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'tax_id_checkout_settings_page_after', array( __CLASS__, 'pro_box' ) );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Tax_ID_Checkout_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'cedulio' ) . '</a>' );
		return $links;
	}

	/**
	 * Warn about duplicate fields when the Brazilian Market plugin is still active, and confirm the settings import.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'woocommerce_page_' . Tax_ID_Checkout_Settings::PAGE ), true ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( class_exists( 'Extra_Checkout_Fields_For_Brazil' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Cedulio already adds CPF/CNPJ, house number and neighborhood, and keeps the same order data. Deactivate "Brazilian Market on WooCommerce" so customers do not see those fields twice.', 'cedulio' )
			);
		} elseif ( 'wcbcf' === get_option( 'tax_id_checkout_imported' ) && 'woocommerce_page_' . Tax_ID_Checkout_Settings::PAGE === $screen->id ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html__( 'Your Brazilian Market settings were imported. Orders and customers keep their CPF, CNPJ, number and neighborhood.', 'cedulio' )
			);
		}
	}

	/**
	 * Pro features box under the settings form (free version only).
	 */
	public static function pro_box() {
		if ( Tax_ID_Checkout::is_pro() ) {
			return;
		}

		$features = array(
			__( 'Company lookup: type a CNPJ (Brazil) or an EU VAT number and the company name and address fill in by themselves', 'cedulio' ),
			__( 'Discount by payment method: e.g. 10% off with bank transfer, Pix or cash, in both checkouts', 'cedulio' ),
			__( 'Instalments and discounted price under every price: "12 × $10.00 interest-free" and "$95.00 by bank transfer"', 'cedulio' ),
		);
		?>
		<div class="card" style="max-width:640px;margin-top:2em;">
			<h2><?php esc_html_e( 'Cedulio Pro', 'cedulio' ); ?></h2>
			<ul style="list-style:disc;padding-left:1.5em;">
				<?php foreach ( $features as $feature ) : ?>
					<li><?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( Tax_ID_Checkout::upgrade_url() ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( Tax_ID_Checkout::upgrade_url() ); ?>"><?php esc_html_e( 'Upgrade to Pro', 'cedulio' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
