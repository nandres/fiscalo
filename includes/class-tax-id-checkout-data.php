<?php
/**
 * Reading and saving the tax ID on orders and customers.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * One place for meta keys, so classic and block checkout store the same data.
 *
 * Orders:    _billing_tax_id, _billing_tax_id_type, _billing_invoice, _billing_number, _billing_neighborhood,
 *            _shipping_number, _shipping_neighborhood.
 * Customers: the same keys without the leading underscore.
 * Compatibility keys read by many payment and invoicing plugins: _billing_persontype, _billing_cpf and
 * _billing_cnpj for Brazil, _billing_nif for Spain and Portugal.
 */
class Tax_ID_Checkout_Data {

	/**
	 * Check a tax ID with the configured validation mode.
	 *
	 * @param string $country Country code.
	 * @param string $value   Number as typed.
	 * @return array Validator result.
	 */
	public static function check( $country, $value ) {
		return Tax_ID_Checkout_Validator::check( $country, $value, Tax_ID_Checkout_Settings::get( 'validation' ) );
	}

	/**
	 * Whether the tax ID must be filled in.
	 *
	 * @param string    $country Billing country.
	 * @param bool|null $invoice Whether the customer asked for an invoice (null outside invoice mode).
	 * @return bool
	 */
	public static function is_required( $country, $invoice = null ) {
		if ( ! Tax_ID_Checkout_Countries::enabled( $country ) ) {
			return false;
		}
		if ( 'invoice' === Tax_ID_Checkout_Settings::get( 'taxid_mode' ) ) {
			return (bool) $invoice;
		}
		return Tax_ID_Checkout_Countries::required( $country );
	}

	/**
	 * Error message for a value, or '' when it is fine.
	 *
	 * @param string    $country Billing country.
	 * @param string    $value   Number as typed.
	 * @param bool|null $invoice Invoice requested (invoice mode only).
	 * @return string
	 */
	public static function error( $country, $value, $invoice = null ) {
		if ( ! Tax_ID_Checkout_Countries::enabled( $country ) || ( 'invoice' === Tax_ID_Checkout_Settings::get( 'taxid_mode' ) && ! $invoice ) ) {
			return '';
		}

		$label = Tax_ID_Checkout_Countries::get( $country )['label'];

		if ( '' === trim( (string) $value ) ) {
			/* translators: %s: field name, e.g. "CPF or CNPJ". */
			return self::is_required( $country, $invoice ) ? sprintf( __( '%s is a required field.', 'fiscalo' ), '<strong>' . esc_html( $label ) . '</strong>' ) : '';
		}

		if ( ! self::check( $country, $value )['valid'] ) {
			/* translators: %s: field name, e.g. "CPF or CNPJ". */
			return sprintf( __( 'The %s you entered is not valid. Please check the number.', 'fiscalo' ), '<strong>' . esc_html( $label ) . '</strong>' );
		}

		return '';
	}

	/**
	 * The value as it should be stored: formatted when valid, trimmed otherwise.
	 *
	 * @param string $country Country code.
	 * @param string $value   Number as typed.
	 * @return string
	 */
	public static function clean( $country, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		$result = self::check( $country, $value );
		return $result['valid'] ? $result['formatted'] : $value;
	}

	/**
	 * Meta to save for a tax ID, keyed without the "billing_" prefix.
	 *
	 * @param string $country Billing country.
	 * @param string $value   Number as typed.
	 * @return array<string,string> Empty strings mean "delete".
	 */
	private static function meta_for( $country, $value ) {
		$value  = self::clean( $country, $value );
		$result = '' === $value ? null : self::check( $country, $value );
		$meta   = array(
			'tax_id'      => $value,
			'tax_id_type' => $result ? $result['type'] : '',
		);

		if ( 'BR' === $country ) {
			$company            = $result && 'cnpj' === $result['type'];
			$meta['persontype'] = $result ? ( $company ? '2' : '1' ) : '';
			$meta['cpf']        = $result && ! $company ? $value : '';
			$meta['cnpj']       = $company ? $value : '';
		}
		if ( in_array( $country, array( 'ES', 'PT' ), true ) ) {
			$meta['nif'] = $value;
		}

		/**
		 * Filters the meta saved for a tax ID (keys without the "billing_" prefix), e.g. to add keys
		 * read by an invoicing plugin.
		 *
		 * @param array  $meta    Meta values.
		 * @param string $country Billing country.
		 * @param array  $result  Validator result, or null when empty.
		 */
		return apply_filters( 'tax_id_checkout_meta', $meta, $country, $result );
	}

	/**
	 * Save the tax ID on an order (the order is saved by the caller).
	 *
	 * @param WC_Order  $order   Order.
	 * @param string    $country Billing country.
	 * @param string    $value   Number as typed.
	 * @param bool|null $invoice Invoice requested (null outside invoice mode).
	 */
	public static function save_order( WC_Order $order, $country, $value, $invoice = null ) {
		foreach ( self::meta_for( $country, $value ) as $key => $meta_value ) {
			if ( '' === $meta_value ) {
				$order->delete_meta_data( '_billing_' . $key );
			} else {
				$order->update_meta_data( '_billing_' . $key, $meta_value );
			}
		}

		if ( null !== $invoice ) {
			$order->update_meta_data( '_billing_invoice', $invoice ? 'yes' : 'no' );
		}

		/**
		 * Fires after the tax ID was set on an order (before the order is saved).
		 *
		 * @param WC_Order $order   Order.
		 * @param string   $country Billing country.
		 */
		do_action( 'tax_id_checkout_order_tax_id_set', $order, $country );
	}

	/**
	 * Save the tax ID on a customer (the customer is saved by the caller).
	 *
	 * @param WC_Customer $customer Customer.
	 * @param string      $country  Billing country.
	 * @param string      $value    Number as typed.
	 */
	public static function save_customer( WC_Customer $customer, $country, $value ) {
		foreach ( self::meta_for( $country, $value ) as $key => $meta_value ) {
			if ( '' === $meta_value ) {
				$customer->delete_meta_data( 'billing_' . $key );
			} else {
				$customer->update_meta_data( 'billing_' . $key, $meta_value );
			}
		}
	}

	/**
	 * Tax ID of an order, including orders placed with the Brazilian Market plugin.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_tax_id( WC_Order $order ) {
		foreach ( array( '_billing_tax_id', '_billing_cnpj', '_billing_cpf', '_billing_nif' ) as $key ) {
			$value = (string) $order->get_meta( $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Saved tax ID of a customer, including data from the Brazilian Market plugin.
	 *
	 * @param WC_Customer $customer Customer.
	 * @return string
	 */
	public static function customer_tax_id( WC_Customer $customer ) {
		$value = (string) $customer->get_meta( 'billing_tax_id' );
		if ( '' !== $value ) {
			return $value;
		}
		if ( '2' === (string) $customer->get_meta( 'billing_persontype' ) ) {
			return (string) $customer->get_meta( 'billing_cnpj' );
		}
		return (string) $customer->get_meta( 'billing_cpf' );
	}

	/**
	 * Label for an order's tax ID.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_label( WC_Order $order ) {
		return self::type_label( (string) $order->get_meta( '_billing_tax_id_type' ), $order->get_billing_country() );
	}

	/**
	 * Short name of a document type (CPF, RUC, NIF…), or the country's field label when the type is generic.
	 *
	 * @param string $type    Document type from the validator.
	 * @param string $country Billing country.
	 * @return string
	 */
	public static function type_label( $type, $country ) {
		$labels = array(
			'cpf'  => 'CPF',
			'cnpj' => 'CNPJ',
			'cuit' => 'CUIT',
			'dni'  => 'DNI',
			'rut'  => 'RUT',
			'ruc'  => 'RUC',
			'ci'   => 'CI',
			'nit'  => 'NIT',
			'rfc'  => 'RFC',
			'nif'  => 'NIF',
			'nie'  => 'NIE',
			'cif'  => 'CIF',
			'cf'   => 'Codice fiscale',
			'piva' => 'Partita IVA',
			'vat'  => __( 'VAT number', 'fiscalo' ),
		);
		if ( isset( $labels[ $type ] ) ) {
			return $labels[ $type ];
		}
		return Tax_ID_Checkout_Countries::get( $country )['label'];
	}
}
