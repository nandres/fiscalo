<?php
/**
 * Showing the data: order screen, addresses, emails, order details and user profile.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display and admin editing of the saved data.
 *
 * The tax ID is printed as part of the billing address (under the company), so it shows the same way on the
 * order screen, in emails, on the thank-you page (classic and block themes) and in My Account.
 */
class Tax_ID_Checkout_Display {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_billing_fields' ), 30, 2 );
		add_filter( 'woocommerce_admin_shipping_fields', array( __CLASS__, 'admin_shipping_fields' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'admin_saved' ), 60 );
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'profile_fields' ) );

		add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'order_address' ), 10, 2 );
		add_filter( 'woocommerce_order_formatted_shipping_address', array( __CLASS__, 'order_address' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_address_formatted_address', array( __CLASS__, 'account_address' ), 10, 3 );
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'replacements' ), 10, 2 );
		add_filter( 'render_block_woocommerce/order-confirmation-additional-fields-wrapper', array( __CLASS__, 'confirmation_section' ) );
	}

	/**
	 * Editable tax ID, house number and neighborhood (shown inside the address itself).
	 *
	 * @param array         $fields Billing fields.
	 * @param WC_Order|null $order  Order (WooCommerce 8.6+).
	 * @return array
	 */
	public static function admin_billing_fields( $fields, $order = null ) {
		$fields['tax_id'] = array(
			'label' => $order instanceof WC_Order ? Tax_ID_Checkout_Data::order_label( $order ) : __( 'Tax ID', 'cedulio' ),
			'show'  => false,
		);
		if ( $order instanceof WC_Order && 'yes' === $order->get_meta( '_billing_invoice' ) ) {
			$fields['invoice'] = array(
				'label'   => __( 'Invoice requested', 'cedulio' ),
				'show'    => true,
				'type'    => 'select',
				'options' => array(
					'yes' => __( 'Yes', 'cedulio' ),
					'no'  => __( 'No', 'cedulio' ),
				),
			);
		}
		return self::address_extras( $fields );
	}

	/**
	 * Editable house number and neighborhood on the shipping address.
	 *
	 * @param array $fields Shipping fields.
	 * @return array
	 */
	public static function admin_shipping_fields( $fields ) {
		return self::address_extras( $fields );
	}

	/**
	 * Insert house number after address line 1 and neighborhood after line 2 (both shown in the address itself).
	 *
	 * @param array $fields Admin address fields.
	 * @return array
	 */
	private static function address_extras( $fields ) {
		$out = array();
		foreach ( $fields as $key => $field ) {
			$out[ $key ] = $field;
			if ( 'address_1' === $key ) {
				$out['number'] = array(
					'label' => __( 'House number', 'cedulio' ),
					'show'  => false,
				);
			}
			if ( 'address_2' === $key ) {
				$out['neighborhood'] = array(
					'label' => __( 'Neighborhood', 'cedulio' ),
					'show'  => false,
				);
			}
		}
		return $out;
	}

	/**
	 * After an admin edits an order, format the tax ID and refresh the compatibility keys.
	 *
	 * @param int $order_id Order id.
	 */
	public static function admin_saved( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$invoice = $order->get_meta( '_billing_invoice' );
		Tax_ID_Checkout_Data::save_order( $order, $order->get_billing_country(), (string) $order->get_meta( '_billing_tax_id' ), '' === $invoice ? null : 'yes' === $invoice );
		$order->save();
	}

	/**
	 * Fields on the user profile screen.
	 *
	 * @param array $fields Profile fields.
	 * @return array
	 */
	public static function profile_fields( $fields ) {
		$extras = array(
			'billing_number'       => __( 'House number', 'cedulio' ),
			'billing_neighborhood' => __( 'Neighborhood', 'cedulio' ),
			'billing_tax_id'       => __( 'Tax ID', 'cedulio' ),
		);
		foreach ( $extras as $key => $label ) {
			$fields['billing']['fields'][ $key ] = array(
				'label'       => $label,
				'description' => '',
			);
		}
		$fields['shipping']['fields']['shipping_number']       = array(
			'label'       => __( 'House number', 'cedulio' ),
			'description' => '',
		);
		$fields['shipping']['fields']['shipping_neighborhood'] = array(
			'label'       => __( 'Neighborhood', 'cedulio' ),
			'description' => '',
		);
		return $fields;
	}

	/**
	 * Add house number and neighborhood to an order address.
	 *
	 * @param array    $address Address parts.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public static function order_address( $address, $order ) {
		if ( ! is_array( $address ) || ! $order instanceof WC_Order ) {
			return $address;
		}
		$group                   = doing_filter( 'woocommerce_order_formatted_shipping_address' ) ? 'shipping' : 'billing';
		$address['number']       = (string) $order->get_meta( "_{$group}_number" );
		$address['neighborhood'] = (string) $order->get_meta( "_{$group}_neighborhood" );
		if ( 'billing' === $group ) {
			$address['tax_id']       = Tax_ID_Checkout_Data::order_tax_id( $order );
			$address['tax_id_label'] = Tax_ID_Checkout_Data::order_label( $order );
		}
		return $address;
	}

	/**
	 * Add house number and neighborhood to the addresses in My Account.
	 *
	 * @param array  $address     Address parts.
	 * @param int    $customer_id Customer id.
	 * @param string $type        billing or shipping.
	 * @return array
	 */
	public static function account_address( $address, $customer_id, $type ) {
		$address['number']       = (string) get_user_meta( $customer_id, $type . '_number', true );
		$address['neighborhood'] = (string) get_user_meta( $customer_id, $type . '_neighborhood', true );
		if ( 'billing' === $type ) {
			$customer                = new WC_Customer( $customer_id );
			$address['tax_id']       = Tax_ID_Checkout_Data::customer_tax_id( $customer );
			$address['tax_id_label'] = Tax_ID_Checkout_Data::type_label( (string) $customer->get_meta( 'billing_tax_id_type' ), $customer->get_billing_country() );
		}
		return $address;
	}

	/**
	 * "Street, number" on the first line, the neighborhood under the second and "CPF: …" under the company,
	 * in every country format.
	 *
	 * @param array $replacements Placeholder replacements.
	 * @param array $args         Address parts.
	 * @return array
	 */
	public static function replacements( $replacements, $args ) {
		if ( ! empty( $args['number'] ) ) {
			$line                              = trim( ( isset( $args['address_1'] ) ? $args['address_1'] : '' ) . ', ' . $args['number'], ', ' );
			$replacements['{address_1}']       = $line;
			$replacements['{address_1_upper}'] = wc_strtoupper( $line );
		}
		if ( ! empty( $args['neighborhood'] ) ) {
			$line                              = trim( ( isset( $args['address_2'] ) ? $args['address_2'] : '' ) . "\n" . $args['neighborhood'] );
			$replacements['{address_2}']       = $line;
			$replacements['{address_2_upper}'] = wc_strtoupper( $line );
		}
		if ( ! empty( $args['tax_id'] ) ) {
			$line                            = trim( ( isset( $args['company'] ) ? $args['company'] : '' ) . "\n" . $args['tax_id_label'] . ': ' . $args['tax_id'] );
			$replacements['{company}']       = $line;
			$replacements['{company_upper}'] = wc_strtoupper( $line );
		}
		return $replacements;
	}

	/**
	 * Block themes show an "Additional information" heading on the thank-you page whenever any extra field has a
	 * value, even fields hidden from the confirmation (ours are printed in the address). Drop it when nothing is left.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public static function confirmation_section( $content ) {
		$fields   = Tax_ID_Checkout_Blocks::controller();
		$order_id = absint( get_query_var( 'order-received' ) ? get_query_var( 'order-received' ) : get_query_var( 'view-order' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $fields || ! $order ) {
			return $content;
		}

		$visible = $fields->filter_fields_for_order_confirmation(
			array_merge(
				$fields->get_order_additional_fields_with_values( $order, 'contact', 'other', 'view' ),
				$fields->get_order_additional_fields_with_values( $order, 'order', 'other', 'view' )
			),
			array(
				'caller' => 'Tax_ID_Checkout_Display::confirmation_section',
				'order'  => $order,
			)
		);

		return $visible ? $content : '';
	}
}
