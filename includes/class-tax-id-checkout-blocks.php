<?php
/**
 * Fields for the block checkout (and My Account, which shows the same registered fields).
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Registers the fields with WooCommerce's additional checkout fields API.
 *
 * A field label cannot change with the country in the block checkout, so the tax ID is registered once
 * per country label (CPF or CNPJ, RUC or CI, RUT…) and rules show only the one for the billing country.
 */
class Tax_ID_Checkout_Blocks {

	const NS = 'tax-id-checkout';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_order' ), 10, 2 );
		add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'mirror_customer' ), 10, 4 );
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'remove_from_admin' ), 20 );
		add_filter( 'woocommerce_admin_shipping_fields', array( __CLASS__, 'remove_from_admin' ), 20 );
		add_filter( 'woocommerce_address_to_edit', array( __CLASS__, 'account_address_fields' ), 20, 2 );
	}

	/**
	 * My Account → Addresses: WooCommerce only prints house number and neighborhood when the saved country
	 * already uses them, and always at the end of the form. Print them for every country right after the
	 * street lines, so the script can show or hide them as the customer changes the country.
	 *
	 * @param array  $address      Address fields.
	 * @param string $address_type billing or shipping.
	 * @return array
	 */
	public static function account_address_fields( $address, $address_type ) {
		$controller = self::controller();
		if ( ! $controller || ! is_array( $address ) || ! method_exists( $controller, 'get_fields_for_location' ) ) {
			return $address;
		}

		$registered = $controller->get_fields_for_location( 'address' );
		$customer   = new WC_Customer( get_current_user_id() );
		$slugs      = array(
			'number'       => array( Tax_ID_Checkout_Countries::number_countries(), Tax_ID_Checkout_Settings::enabled( 'number_required' ), 55 ),
			'neighborhood' => array( Tax_ID_Checkout_Countries::neighborhood_countries(), Tax_ID_Checkout_Settings::enabled( 'neighborhood_required' ), 65 ),
		);

		foreach ( $slugs as $slug => $info ) {
			list( $countries, $required, $priority ) = $info;
			$key = self::NS . '/' . $slug;
			if ( ! $countries || ! isset( $registered[ $key ] ) ) {
				continue;
			}

			$field_key = CheckoutFields::get_group_key( $address_type ) . $key;
			if ( ! isset( $address[ $field_key ] ) ) {
				$field          = $registered[ $key ];
				$field['value'] = method_exists( $controller, 'get_field_from_object' )
					? $controller->get_field_from_object( $key, $customer, $address_type )
					: $customer->get_meta( $field_key );
				$field          = $controller->prepare_form_field( $field );
			} else {
				$field = $address[ $field_key ];
			}

			$attributes                          = isset( $field['custom_attributes'] ) ? (array) $field['custom_attributes'] : array();
			$attributes['data-tax-id-address']   = $address_type;
			$attributes['data-tax-id-countries'] = implode( ',', $countries );

			$field['required']          = (bool) $required;
			$field['priority']          = $priority;
			$field['custom_attributes'] = $attributes;
			$address[ $field_key ]      = $field;
		}

		uasort(
			$address,
			static function ( $a, $b ) {
				$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 1000;
				$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 1000;
				return $pa <=> $pb;
			}
		);

		return $address;
	}

	/**
	 * WooCommerce's checkout fields controller.
	 *
	 * @return CheckoutFields|null
	 */
	public static function controller() {
		if ( ! class_exists( Package::class ) ) {
			return null;
		}
		try {
			return Package::container()->get( CheckoutFields::class );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Field id holding the tax ID for a country.
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	public static function tax_field( $country ) {
		return self::NS . '/' . Tax_ID_Checkout_Countries::group_for( $country );
	}

	/**
	 * Countries a tax ID group shows for, as an "in" or "not in" list, or null when the group is off.
	 *
	 * @param array $group Group from Tax_ID_Checkout_Countries::groups().
	 * @return array{0:string,1:string[]}|null ['in' or 'not_in', country codes]
	 */
	private static function group_match( $group ) {
		$enabled = (array) Tax_ID_Checkout_Settings::get( 'taxid_countries' );
		$listed  = array();
		foreach ( Tax_ID_Checkout_Countries::groups() as $other ) {
			$listed = array_merge( $listed, (array) $other['countries'] );
		}

		if ( null !== $group['countries'] ) {
			$codes = $enabled ? array_values( array_intersect( $group['countries'], $enabled ) ) : $group['countries'];
			return $codes ? array( 'in', $codes ) : null;
		}

		if ( ! $enabled ) {
			return array( 'not_in', $listed );
		}
		$codes = array_values( array_diff( $enabled, $listed ) );
		return $codes ? array( 'in', $codes ) : null;
	}

	/**
	 * JSON schema condition on the billing (or current address) country.
	 *
	 * @param string   $how      'in' or 'not_in'.
	 * @param string[] $codes    Country codes.
	 * @param string   $address  'billing_address' or 'address' (the address being edited).
	 * @return array Unwrapped rule.
	 */
	private static function country_rule( $how, $codes, $address = 'billing_address' ) {
		$condition = 'in' === $how ? array( 'enum' => array_values( $codes ) ) : array( 'not' => array( 'enum' => array_values( $codes ) ) );
		return array(
			'customer' => array(
				'properties' => array(
					$address => array(
						'properties' => array( 'country' => $condition ),
						'required'   => array( 'country' ),
					),
				),
			),
		);
	}

	/**
	 * Wrap an unwrapped rule so it can be combined with allOf/anyOf/not.
	 *
	 * @param array $rule Unwrapped rule.
	 * @return array
	 */
	private static function wrap( $rule ) {
		return array( 'properties' => $rule );
	}

	/**
	 * Rule: the customer ticked "I need an invoice".
	 *
	 * @return array Unwrapped rule.
	 */
	private static function invoice_rule() {
		return array(
			'customer' => array(
				'properties' => array(
					'additional_fields' => array(
						'properties' => array( self::NS . '/invoice' => array( 'const' => true ) ),
						'required'   => array( self::NS . '/invoice' ),
					),
				),
			),
		);
	}

	/**
	 * Register all fields.
	 */
	public static function register() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$invoice_mode = 'invoice' === Tax_ID_Checkout_Settings::get( 'taxid_mode' );
		$required     = Tax_ID_Checkout_Settings::get( 'taxid_required' );
		$required_in  = (array) Tax_ID_Checkout_Settings::get( 'taxid_required_countries' );
		$any_country  = array();

		foreach ( Tax_ID_Checkout_Countries::groups() as $slug => $group ) {
			$match = self::group_match( $group );
			if ( null === $match ) {
				continue;
			}
			list( $how, $codes ) = $match;
			$any_country[]       = $match;

			$shown  = self::country_rule( $how, $codes );
			$hidden = self::country_rule( 'in' === $how ? 'not_in' : 'in', $codes );

			if ( $invoice_mode ) {
				$is_required = array( 'allOf' => array( self::wrap( $shown ), self::wrap( self::invoice_rule() ) ) );
				$hidden      = array( 'anyOf' => array( self::wrap( $hidden ), array( 'not' => self::wrap( self::invoice_rule() ) ) ) );
			} elseif ( 'yes' === $required ) {
				$is_required = $shown;
			} elseif ( 'countries' === $required ) {
				$required_codes = 'in' === $how ? array_intersect( $codes, $required_in ) : array_diff( $required_in, $codes );
				$is_required    = $required_codes ? self::country_rule( 'in', $required_codes ) : false;
			} else {
				$is_required = false;
			}

			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::NS . '/' . $slug,
					'label'                      => $group['label'],
					/* translators: %s: field name, e.g. "RUT". */
					'optionalLabel'              => sprintf( __( '%s (optional)', 'cedulio' ), $group['label'] ),
					'location'                   => 'contact',
					'type'                       => 'text',
					'required'                   => $is_required,
					'hidden'                     => $hidden,
					'show_in_order_confirmation' => false,
					'attributes'                 => array(
						'autocomplete'           => 'off',
						'maxLength'              => 30,
						'data-tax-id-checkout'   => $slug,
						'data-tax-id-countries'  => 'in' === $how ? implode( ',', $codes ) : '',
					),
					'sanitize_callback'          => static function ( $value ) use ( $slug ) {
						return self::sanitize( $slug, $value );
					},
					'validate_callback'          => static function ( $value ) use ( $slug ) {
						return self::validate( $slug, $value );
					},
				)
			);

			add_filter( 'woocommerce_get_default_value_for_' . self::NS . '/' . $slug, array( __CLASS__, 'default_tax_id' ), 10, 3 );
		}

		if ( $invoice_mode && $any_country ) {
			$invoice = array(
				'id'                         => self::NS . '/invoice',
				'label'                      => Tax_ID_Checkout_Settings::get( 'invoice_label' ),
				'location'                   => 'contact',
				'type'                       => 'checkbox',
				'show_in_order_confirmation' => false,
			);
			$enabled = (array) Tax_ID_Checkout_Settings::get( 'taxid_countries' );
			if ( $enabled ) {
				$invoice['hidden'] = self::country_rule( 'not_in', $enabled );
			}
			woocommerce_register_additional_checkout_field( $invoice );
		}

		self::register_address_field( 'number', __( 'House number', 'cedulio' ), Tax_ID_Checkout_Countries::number_countries(), Tax_ID_Checkout_Settings::enabled( 'number_required' ) );
		self::register_address_field( 'neighborhood', __( 'Neighborhood', 'cedulio' ), Tax_ID_Checkout_Countries::neighborhood_countries(), Tax_ID_Checkout_Settings::enabled( 'neighborhood_required' ) );
	}

	/**
	 * Register house number or neighborhood for some countries.
	 *
	 * @param string   $slug      Field slug.
	 * @param string   $label     Label.
	 * @param string[] $countries Countries that show it.
	 * @param bool     $required  Required in those countries.
	 */
	private static function register_address_field( $slug, $label, $countries, $required ) {
		if ( ! $countries ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => self::NS . '/' . $slug,
				'label'                      => $label,
				/* translators: %s: field name, e.g. "Neighborhood". */
				'optionalLabel'              => sprintf( __( '%s (optional)', 'cedulio' ), $label ),
				'location'                   => 'address',
				'type'                       => 'text',
				'required'                   => $required ? self::country_rule( 'in', $countries, 'address' ) : false,
				'hidden'                     => self::country_rule( 'not_in', $countries, 'address' ),
				'show_in_order_confirmation' => false,
				'attributes'                 => array( 'maxLength' => 'number' === $slug ? 20 : 100 ),
			)
		);

		add_filter( 'woocommerce_get_default_value_for_' . self::NS . '/' . $slug, array( __CLASS__, 'default_address_value' ), 10, 3 );
	}

	/**
	 * Country to check a value against for a field group.
	 *
	 * @param string $slug  Group slug.
	 * @param string $value Value (a VAT number may carry its country prefix).
	 * @return string
	 */
	private static function country_for( $slug, $value ) {
		if ( 'vat' === $slug ) {
			$prefix = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 2 ) );
			$prefix = 'EL' === $prefix ? 'GR' : $prefix;
			if ( in_array( $prefix, Tax_ID_Checkout_Countries::VAT, true ) ) {
				return $prefix;
			}
			return WC()->customer ? WC()->customer->get_billing_country() : '';
		}
		return 'other' === $slug ? 'ZZ' : strtoupper( $slug );
	}

	/**
	 * Format a value when it is valid.
	 *
	 * @param string $slug  Group slug.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public static function sanitize( $slug, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return Tax_ID_Checkout_Data::clean( self::country_for( $slug, $value ), $value );
	}

	/**
	 * Inline validation in the block checkout and My Account.
	 *
	 * @param string $slug  Group slug.
	 * @param mixed  $value Value.
	 * @return true|WP_Error
	 */
	public static function validate( $slug, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return true;
		}
		$country = self::country_for( $slug, $value );
		if ( Tax_ID_Checkout_Data::check( $country, $value )['valid'] ) {
			return true;
		}
		$groups = Tax_ID_Checkout_Countries::groups();
		return new WP_Error(
			'tax_id_checkout_invalid',
			/* translators: %s: field name, e.g. "CPF or CNPJ". */
			sprintf( __( 'The %s you entered is not valid. Please check the number.', 'cedulio' ), $groups[ $slug ]['label'] )
		);
	}

	/**
	 * Final check with the billing country of the order, then store the unified meta.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 * @throws RouteException When the tax ID is missing or invalid.
	 */
	public static function save_order( $order, $request ) {
		$fields = self::controller();
		if ( ! $fields ) {
			return;
		}

		$country = $order->get_billing_country();
		$invoice = null;
		$value   = '';

		if ( 'invoice' === Tax_ID_Checkout_Settings::get( 'taxid_mode' ) ) {
			$invoice = (bool) $fields->get_field_from_object( self::NS . '/invoice', $order, 'other' );
		}
		if ( Tax_ID_Checkout_Countries::enabled( $country ) && false !== $invoice ) {
			$value = (string) $fields->get_field_from_object( self::tax_field( $country ), $order, 'other' );
		}

		// Only block the order itself; draft updates while the customer types are left alone.
		if ( 'POST' === $request->get_method() ) {
			$error = Tax_ID_Checkout_Data::error( $country, $value, $invoice );
			if ( '' !== $error ) {
				throw new RouteException( 'tax_id_checkout_invalid', esc_html( wp_strip_all_tags( $error ) ), 400 );
			}
		}

		Tax_ID_Checkout_Data::save_order( $order, $country, $value, $invoice );

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( array( 'number', 'neighborhood' ) as $field ) {
				$field_value = $fields->is_field( self::NS . '/' . $field ) ? (string) $fields->get_field_from_object( self::NS . '/' . $field, $order, $group ) : '';
				if ( '' === $field_value ) {
					$order->delete_meta_data( "_{$group}_{$field}" );
				} else {
					$order->update_meta_data( "_{$group}_{$field}", $field_value );
				}
			}
		}
	}

	/**
	 * Copy values saved on a customer to the plain meta keys used by the classic checkout and other plugins.
	 *
	 * @param string  $key       Field id.
	 * @param mixed   $value     Value.
	 * @param string  $group     billing, shipping or other.
	 * @param WC_Data $wc_object Customer or order.
	 */
	public static function mirror_customer( $key, $value, $group, $wc_object ) {
		if ( ! $wc_object instanceof WC_Customer || 0 !== strpos( $key, self::NS . '/' ) ) {
			return;
		}

		$slug  = substr( $key, strlen( self::NS ) + 1 );
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( in_array( $slug, array( 'number', 'neighborhood' ), true ) ) {
			$wc_object->update_meta_data( "{$group}_{$slug}", $value );
			return;
		}

		$country = $wc_object->get_billing_country();
		if ( 'invoice' !== $slug && self::tax_field( $country ) === $key && '' !== $value ) {
			Tax_ID_Checkout_Data::save_customer( $wc_object, $country, $value );
		}
	}

	/**
	 * Save a field value on a customer through WooCommerce (keeps My Account and the block checkout up to date).
	 *
	 * @param WC_Customer $customer Customer.
	 * @param string      $key      Field id.
	 * @param string      $value    Value.
	 * @param string      $group    billing, shipping or other.
	 */
	public static function persist_for_customer( $customer, $key, $value, $group ) {
		$fields = self::controller();
		if ( $fields && $fields->is_field( $key ) ) {
			$fields->persist_field_for_customer( $key, $value, $customer, $group );
		}
	}

	/**
	 * Prefill the tax ID from data saved by the classic checkout or the Brazilian Market plugin.
	 *
	 * @param mixed   $value     Default value (null when none).
	 * @param string  $group     Group.
	 * @param WC_Data $wc_object Customer or order.
	 * @return mixed
	 */
	public static function default_tax_id( $value, $group, $wc_object ) {
		if ( null !== $value ) {
			return $value;
		}
		$key = substr( current_filter(), strlen( 'woocommerce_get_default_value_for_' ) );

		if ( $wc_object instanceof WC_Customer && self::tax_field( $wc_object->get_billing_country() ) === $key ) {
			$saved = Tax_ID_Checkout_Data::customer_tax_id( $wc_object );
			return '' === $saved ? null : $saved;
		}
		if ( $wc_object instanceof WC_Order && self::tax_field( $wc_object->get_billing_country() ) === $key ) {
			$saved = Tax_ID_Checkout_Data::order_tax_id( $wc_object );
			return '' === $saved ? null : $saved;
		}
		return null;
	}

	/**
	 * Prefill house number and neighborhood from the plain meta keys.
	 *
	 * @param mixed   $value     Default value (null when none).
	 * @param string  $group     billing or shipping.
	 * @param WC_Data $wc_object Customer or order.
	 * @return mixed
	 */
	public static function default_address_value( $value, $group, $wc_object ) {
		if ( null !== $value || ! in_array( $group, array( 'billing', 'shipping' ), true ) ) {
			return $value;
		}
		$slug  = substr( current_filter(), strlen( 'woocommerce_get_default_value_for_' . self::NS . '/' ) );
		$key   = ( $wc_object instanceof WC_Order ? '_' : '' ) . "{$group}_{$slug}";
		$saved = (string) $wc_object->get_meta( $key );
		return '' === $saved ? null : $saved;
	}

	/**
	 * The order screen shows the unified fields from Tax_ID_Checkout_Display instead of one per country.
	 *
	 * @param array $fields Admin fields.
	 * @return array
	 */
	public static function remove_from_admin( $fields ) {
		foreach ( $fields as $key => $field ) {
			// WooCommerce inserts address fields with array_splice(), so their keys are numeric and the name is in "id".
			$name = isset( $field['id'] ) ? (string) $field['id'] : (string) $key;
			if ( 0 === strpos( $name, self::NS . '/' ) || false !== strpos( $name, '/' . self::NS . '/' ) ) {
				unset( $fields[ $key ] );
			}
		}
		return $fields;
	}
}
