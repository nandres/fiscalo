<?php
/**
 * Front-end script for the checkout and My Account.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads assets/js/checkout.js with the per-country settings it needs.
 */
class Tax_ID_Checkout_Assets {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Settings passed to the script.
	 *
	 * @return array
	 */
	public static function config() {
		$groups = Tax_ID_Checkout_Countries::groups();
		return array(
			'mode'       => Tax_ID_Checkout_Settings::get( 'taxid_mode' ),
			'enabled'    => array_values( (array) Tax_ID_Checkout_Settings::get( 'taxid_countries' ) ),
			'required'   => Tax_ID_Checkout_Settings::get( 'taxid_required' ),
			'requiredIn' => array_values( (array) Tax_ID_Checkout_Settings::get( 'taxid_required_countries' ) ),
			'named'      => Tax_ID_Checkout_Countries::named(),
			'vat'        => Tax_ID_Checkout_Countries::VAT,
			'vatField'   => array(
				'label'       => $groups['vat']['label'],
				'placeholder' => '',
			),
			'other'      => array(
				'label'       => $groups['other']['label'],
				'placeholder' => '',
			),
			'autofill'   => Tax_ID_Checkout_Settings::enabled( 'postcode_autofill' ) ? Tax_ID_Checkout_Postcode::countries() : array(),
			'rest'       => esc_url_raw( rest_url( Tax_ID_Checkout_Postcode::ROUTE_NAMESPACE . '/postcode' ) ),
			'base'       => WC()->countries->get_base_country(),
			'i18n'       => array(
				'optional' => __( 'optional', 'cedulio' ),
			),
		);
	}

	/**
	 * Enqueue on the checkout and My Account.
	 */
	public static function enqueue() {
		if ( ! ( is_checkout() || is_account_page() ) || is_order_received_page() ) {
			return;
		}

		$deps = self::is_block_checkout() ? array( 'wp-data', 'wc-blocks-data-store' ) : array();
		wp_enqueue_script( 'tax-id-checkout', TAX_ID_CHECKOUT_URL . 'assets/js/checkout.js', $deps, TAX_ID_CHECKOUT_VERSION, true );
		wp_add_inline_script( 'tax-id-checkout', 'window.taxIdCheckout = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Whether the current page holds the block checkout.
	 *
	 * @return bool
	 */
	public static function is_block_checkout() {
		return function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' );
	}
}
