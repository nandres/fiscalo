<?php
/**
 * What the tax ID is called in each country.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Country registry: field label and example per country, and the groups used by the block checkout.
 */
class Tax_ID_Checkout_Countries {

	/**
	 * Countries that ask for a VAT number (EU, plus the UK, Switzerland and Norway).
	 *
	 * @var string[]
	 */
	const VAT = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'GR', 'FI', 'FR', 'HR', 'HU', 'IE', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'RO', 'SE', 'SI', 'SK', 'GB', 'CH', 'NO' );

	/**
	 * Countries with their own label and example.
	 *
	 * @return array<string,array{label:string,placeholder:string}>
	 */
	public static function named() {
		$countries = array(
			'BR' => array( __( 'CPF or CNPJ', 'tax-id-checkout' ), '000.000.000-00' ),
			'PY' => array( __( 'RUC or CI', 'tax-id-checkout' ), '1234567-8' ),
			'AR' => array( __( 'CUIT, CUIL or DNI', 'tax-id-checkout' ), '20-12345678-6' ),
			'CL' => array( __( 'RUT', 'tax-id-checkout' ), '12.345.678-5' ),
			'UY' => array( __( 'RUT or CI', 'tax-id-checkout' ), '1.234.567-2' ),
			'PE' => array( __( 'RUC or DNI', 'tax-id-checkout' ), '20123456789' ),
			'CO' => array( __( 'NIT or ID number (cédula)', 'tax-id-checkout' ), '900.123.456-7' ),
			'EC' => array( __( 'RUC or ID number (cédula)', 'tax-id-checkout' ), '1712345678' ),
			'BO' => array( __( 'NIT or CI', 'tax-id-checkout' ), '1234567' ),
			'VE' => array( __( 'RIF', 'tax-id-checkout' ), 'J-12345678-9' ),
			'MX' => array( __( 'RFC', 'tax-id-checkout' ), 'XAXX010101000' ),
			'GT' => array( __( 'NIT', 'tax-id-checkout' ), '1234567-8' ),
			'SV' => array( __( 'NIT or DUI', 'tax-id-checkout' ), '01234567-8' ),
			'HN' => array( __( 'RTN', 'tax-id-checkout' ), '08011999123456' ),
			'NI' => array( __( 'RUC', 'tax-id-checkout' ), 'J0310000000000' ),
			'CR' => array( __( 'ID number (cédula)', 'tax-id-checkout' ), '1-2345-6789' ),
			'PA' => array( __( 'RUC', 'tax-id-checkout' ), '8-123-456' ),
			'DO' => array( __( 'RNC or ID number (cédula)', 'tax-id-checkout' ), '101-01234-5' ),
			'ES' => array( __( 'NIF / NIE / CIF', 'tax-id-checkout' ), '12345678Z' ),
			'PT' => array( __( 'NIF', 'tax-id-checkout' ), '123456789' ),
			'IT' => array( __( 'Codice fiscale or Partita IVA', 'tax-id-checkout' ), 'RSSMRA80A01H501U' ),
		);

		$named = array();
		foreach ( $countries as $code => $country ) {
			$named[ $code ] = array(
				'label'       => $country[0],
				'placeholder' => $country[1],
			);
		}

		/**
		 * Filters the countries that have their own tax ID label.
		 *
		 * @param array $named Label and placeholder keyed by country code.
		 */
		return apply_filters( 'tax_id_checkout_named_countries', $named );
	}

	/**
	 * Label and example for one country.
	 *
	 * @param string $country Country code.
	 * @return array{label:string,placeholder:string}
	 */
	public static function get( $country ) {
		$named = self::named();
		if ( isset( $named[ $country ] ) ) {
			return $named[ $country ];
		}
		if ( in_array( $country, self::VAT, true ) ) {
			return array(
				'label'       => __( 'VAT number', 'tax-id-checkout' ),
				'placeholder' => ( 'GR' === $country ? 'EL' : $country ) . '…',
			);
		}
		return array(
			'label'       => __( 'Tax ID', 'tax-id-checkout' ),
			'placeholder' => '',
		);
	}

	/**
	 * Whether the tax ID field shows for a country.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public static function enabled( $country ) {
		$countries = Tax_ID_Checkout_Settings::get( 'taxid_countries' );
		return empty( $countries ) || in_array( $country, (array) $countries, true );
	}

	/**
	 * Whether the tax ID is required for a country (before the "I need an invoice" choice).
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public static function required( $country ) {
		$required = Tax_ID_Checkout_Settings::get( 'taxid_required' );
		if ( 'countries' === $required ) {
			return in_array( $country, (array) Tax_ID_Checkout_Settings::get( 'taxid_required_countries' ), true );
		}
		return 'yes' === $required;
	}

	/**
	 * Groups of countries that share one field in the block checkout (its label cannot change with the country).
	 *
	 * @return array<string,array{label:string,placeholder:string,countries:string[]|null}> Keyed by field slug.
	 *         countries is null for the catch-all group (every country not listed in another group).
	 */
	public static function groups() {
		$groups = array();
		foreach ( self::named() as $code => $country ) {
			$groups[ strtolower( $code ) ] = $country + array( 'countries' => array( $code ) );
		}
		$groups['vat']   = array(
			'label'       => __( 'VAT number', 'tax-id-checkout' ),
			'placeholder' => '',
			'countries'   => self::VAT,
		);
		$groups['other'] = array(
			'label'       => __( 'Tax ID', 'tax-id-checkout' ),
			'placeholder' => '',
			'countries'   => null,
		);
		return $groups;
	}

	/**
	 * The group (field slug) used for a country.
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	public static function group_for( $country ) {
		foreach ( self::groups() as $slug => $group ) {
			if ( null !== $group['countries'] && in_array( $country, $group['countries'], true ) ) {
				return $slug;
			}
		}
		return 'other';
	}

	/**
	 * Countries that use the address number field.
	 *
	 * @return string[]
	 */
	public static function number_countries() {
		return (array) Tax_ID_Checkout_Settings::get( 'number_countries' );
	}

	/**
	 * Countries that use the neighborhood field.
	 *
	 * @return string[]
	 */
	public static function neighborhood_countries() {
		return (array) Tax_ID_Checkout_Settings::get( 'neighborhood_countries' );
	}
}
