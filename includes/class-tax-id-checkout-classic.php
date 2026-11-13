<?php
/**
 * Fields for the classic (shortcode) checkout.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the tax ID, invoice checkbox, house number and neighborhood to the classic checkout.
 *
 * House number and neighborhood are address fields shown per country through WooCommerce's
 * country locale, so WooCommerce itself shows, hides and requires them when the country changes.
 * The tax ID belongs to the billing country only; its label and visibility are handled by
 * assets/js/checkout.js and validated here.
 *
 * In My Account these fields come from the block checkout registration instead (see
 * Tax_ID_Checkout_Blocks), so they are left out of the account forms to avoid duplicates.
 */
class Tax_ID_Checkout_Classic {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'billing_fields' ), 20, 2 );
		add_filter( 'woocommerce_default_address_fields', array( __CLASS__, 'address_fields' ) );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'country_locale' ) );
		add_filter( 'woocommerce_country_locale_field_selectors', array( __CLASS__, 'locale_selectors' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'create_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'update_customer' ), 10, 2 );
	}

	/**
	 * Whether these classic fields stay out of the current request: My Account forms and REST requests
	 * (the block checkout's Store API validates addresses against the same field list and uses its own fields).
	 *
	 * @return bool
	 */
	private static function in_account() {
		return ( function_exists( 'is_account_page' ) && is_account_page() ) || WC()->is_rest_api_request();
	}

	/**
	 * Add the tax ID (and the invoice checkbox) to the billing fields.
	 *
	 * @param array  $fields  Billing fields.
	 * @param string $country Billing country.
	 * @return array
	 */
	public static function billing_fields( $fields, $country = '' ) {
		if ( self::in_account() ) {
			return $fields;
		}

		$country = $country ? $country : WC()->countries->get_base_country();
		$details = Tax_ID_Checkout_Countries::get( $country );

		if ( 'invoice' === Tax_ID_Checkout_Settings::get( 'taxid_mode' ) ) {
			$fields['billing_invoice'] = array(
				'type'     => 'checkbox',
				'label'    => Tax_ID_Checkout_Settings::get( 'invoice_label' ),
				'required' => false,
				'class'    => array( 'form-row-wide', 'tax-id-checkout-invoice' ),
				'priority' => 24,
			);
		}

		// Never "required" here: the billing country can change before the order is placed,
		// so validate() decides with the country that was actually submitted.
		$fields['billing_tax_id'] = array(
			'label'        => $details['label'],
			'placeholder'  => $details['placeholder'],
			'required'     => false,
			'class'        => array( 'form-row-wide', 'tax-id-checkout-field' ),
			'autocomplete' => 'off',
			'priority'     => 25,
		);

		return $fields;
	}

	/**
	 * Add house number and neighborhood to billing and shipping addresses (hidden unless the locale shows them).
	 *
	 * @param array $fields Default address fields.
	 * @return array
	 */
	public static function address_fields( $fields ) {
		if ( self::in_account() ) {
			return $fields;
		}

		if ( Tax_ID_Checkout_Countries::number_countries() ) {
			$fields['number'] = array(
				'label'    => __( 'House number', 'cedulio' ),
				'required' => false,
				'hidden'   => true,
				'class'    => array( 'form-row-wide' ),
				'priority' => 55,
			);
		}

		if ( Tax_ID_Checkout_Countries::neighborhood_countries() ) {
			$fields['neighborhood'] = array(
				'label'    => __( 'Neighborhood', 'cedulio' ),
				'required' => false,
				'hidden'   => true,
				'class'    => array( 'form-row-wide' ),
				'priority' => 65,
			);
		}

		return $fields;
	}

	/**
	 * Show house number and neighborhood in the chosen countries; postcode first in Brazil when autofill is on.
	 *
	 * @param array $locale Locale keyed by country.
	 * @return array
	 */
	public static function country_locale( $locale ) {
		if ( self::in_account() ) {
			return $locale;
		}

		foreach ( Tax_ID_Checkout_Countries::number_countries() as $country ) {
			$locale[ $country ]['number'] = array(
				'hidden'   => false,
				'required' => Tax_ID_Checkout_Settings::enabled( 'number_required' ),
			);
		}

		foreach ( Tax_ID_Checkout_Countries::neighborhood_countries() as $country ) {
			$locale[ $country ]['neighborhood'] = array(
				'hidden'   => false,
				'required' => Tax_ID_Checkout_Settings::enabled( 'neighborhood_required' ),
				'label'    => 'MX' === $country ? __( 'Colonia', 'cedulio' ) : __( 'Neighborhood', 'cedulio' ),
			);
		}

		if ( Tax_ID_Checkout_Settings::enabled( 'postcode_autofill' ) ) {
			$locale['BR']['postcode']['priority'] = 45;
		}

		return $locale;
	}

	/**
	 * Let WooCommerce's address script update the new address fields when the country changes.
	 *
	 * @param array $selectors Field selectors keyed by field.
	 * @return array
	 */
	public static function locale_selectors( $selectors ) {
		$selectors['number']       = '#billing_number_field, #shipping_number_field';
		$selectors['neighborhood'] = '#billing_neighborhood_field, #shipping_neighborhood_field';
		return $selectors;
	}

	/**
	 * Store the tax ID formatted (e.g. 52998224725 → 529.982.247-25).
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public static function posted_data( $data ) {
		if ( isset( $data['billing_tax_id'] ) ) {
			$country                = isset( $data['billing_country'] ) ? $data['billing_country'] : '';
			$data['billing_tax_id'] = Tax_ID_Checkout_Data::clean( $country, $data['billing_tax_id'] );
		}
		return $data;
	}

	/**
	 * Whether the posted data asks for an invoice (null outside invoice mode).
	 *
	 * @param array $data Posted checkout data.
	 * @return bool|null
	 */
	private static function invoice( $data ) {
		if ( 'invoice' !== Tax_ID_Checkout_Settings::get( 'taxid_mode' ) ) {
			return null;
		}
		return ! empty( $data['billing_invoice'] );
	}

	/**
	 * Check the tax ID with the country that was submitted.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ) {
		if ( ! array_key_exists( 'billing_tax_id', $data ) ) {
			return;
		}

		$country = isset( $data['billing_country'] ) ? $data['billing_country'] : '';
		$message = Tax_ID_Checkout_Data::error( $country, $data['billing_tax_id'], self::invoice( $data ) );

		if ( '' !== $message ) {
			$errors->add( 'billing_tax_id_validation', $message, array( 'id' => 'billing_tax_id' ) );
		}
	}

	/**
	 * Save the tax ID and its compatibility keys on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted checkout data.
	 */
	public static function create_order( $order, $data ) {
		if ( ! array_key_exists( 'billing_tax_id', $data ) ) {
			return;
		}

		$country = $order->get_billing_country();
		$invoice = self::invoice( $data );
		$value   = Tax_ID_Checkout_Countries::enabled( $country ) && false !== $invoice ? $data['billing_tax_id'] : '';

		Tax_ID_Checkout_Data::save_order( $order, $country, $value, $invoice );
	}

	/**
	 * Save the tax ID on the customer, and keep the block checkout fields in step for the next purchase.
	 *
	 * @param WC_Customer $customer Customer.
	 * @param array       $data     Posted checkout data.
	 */
	public static function update_customer( $customer, $data ) {
		$country = $customer->get_billing_country();

		if ( array_key_exists( 'billing_tax_id', $data ) && '' !== $data['billing_tax_id'] ) {
			Tax_ID_Checkout_Data::save_customer( $customer, $country, $data['billing_tax_id'] );
			Tax_ID_Checkout_Blocks::persist_for_customer( $customer, Tax_ID_Checkout_Blocks::tax_field( $country ), $data['billing_tax_id'], 'other' );
		}

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( array( 'number', 'neighborhood' ) as $field ) {
				if ( isset( $data[ "{$group}_{$field}" ] ) ) {
					Tax_ID_Checkout_Blocks::persist_for_customer( $customer, Tax_ID_Checkout_Blocks::NS . '/' . $field, $data[ "{$group}_{$field}" ], $group );
				}
			}
		}
	}
}
