<?php
/**
 * Tax ID validation and formatting, country by country.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks tax IDs with each country's check-digit rules and returns them nicely formatted.
 *
 * Every check returns an array:
 *   valid     bool        Whether the number passed.
 *   type      string      Document type: cpf, cnpj, ruc, ci, cuit, dni, rut, nit, cc, rfc, nif, nie, cif, cf, piva, vat or other.
 *   company   bool|null   Whether the number belongs to a company (null when unknown).
 *   formatted string      The number in its usual written form (the input, cleaned, when invalid).
 */
class Tax_ID_Checkout_Validator {

	/**
	 * EU (and nearby) VAT number formats, without the country prefix.
	 *
	 * @var array<string,string>
	 */
	const VAT_FORMATS = array(
		'AT' => 'U\d{8}',
		'BE' => '[01]\d{9}',
		'BG' => '\d{9,10}',
		'CY' => '\d{8}[A-Z]',
		'CZ' => '\d{8,10}',
		'DE' => '\d{9}',
		'DK' => '\d{8}',
		'EE' => '\d{9}',
		'GR' => '\d{9}',
		'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
		'FI' => '\d{8}',
		'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}',
		'HR' => '\d{11}',
		'HU' => '\d{8}',
		'IE' => '\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W]',
		'IT' => '\d{11}',
		'LT' => '\d{9}|\d{12}',
		'LU' => '\d{8}',
		'LV' => '\d{11}',
		'MT' => '\d{8}',
		'NL' => '\d{9}B\d{2}',
		'PL' => '\d{10}',
		'PT' => '\d{9}',
		'RO' => '\d{2,10}',
		'SE' => '\d{12}',
		'SI' => '\d{8}',
		'SK' => '\d{10}',
		'GB' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}',
		'CH' => 'E\d{9}(MWST|TVA|IVA)?',
		'NO' => '\d{9}(MVA)?',
	);

	/**
	 * Check a tax ID.
	 *
	 * @param string $country Two-letter country code of the billing address.
	 * @param string $value   Number as typed.
	 * @param string $mode    'strict' (check digits), 'format' (shape only) or 'off'.
	 * @return array{valid:bool,type:string,company:bool|null,formatted:string}
	 */
	public static function check( $country, $value, $mode = 'strict' ) {
		$country = strtoupper( (string) $country );
		$value   = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

		if ( 'off' === $mode ) {
			return self::result( '' !== $value, 'other', null, $value );
		}

		$method = 'check_' . strtolower( $country );
		if ( method_exists( __CLASS__, $method ) ) {
			$result = call_user_func( array( __CLASS__, $method ), $value, 'strict' === $mode );
		} elseif ( isset( self::VAT_FORMATS[ $country ] ) ) {
			$result = self::check_vat( $country, $value );
		} else {
			$result = self::check_other( $value );
		}

		/**
		 * Filters the result of a tax ID check.
		 *
		 * @param array  $result  valid, type, company and formatted.
		 * @param string $country Country code.
		 * @param string $value   Number as typed.
		 * @param string $mode    Validation mode.
		 */
		return apply_filters( 'tax_id_checkout_validate', $result, $country, $value, $mode );
	}

	/**
	 * Build a result array.
	 *
	 * @param bool      $valid     Valid.
	 * @param string    $type      Document type.
	 * @param bool|null $company   Company or person.
	 * @param string    $formatted Formatted number.
	 * @return array
	 */
	private static function result( $valid, $type, $company, $formatted ) {
		return array(
			'valid'     => (bool) $valid,
			'type'      => $type,
			'company'   => $company,
			'formatted' => $formatted,
		);
	}

	/**
	 * Uppercase and keep only letters and digits.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function alnum( $value ) {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( $value ) );
	}

	/**
	 * Keep only digits.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function digits( $value ) {
		return preg_replace( '/\D/', '', $value );
	}

	/**
	 * Weighted sum of digits (weights applied left to right).
	 *
	 * @param string $digits  Digits.
	 * @param int[]  $weights Weights, same length as $digits.
	 * @return int
	 */
	private static function weighted( $digits, $weights ) {
		$sum = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += (int) $digits[ $i ] * $weight;
		}
		return $sum;
	}

	/**
	 * Thousands dots for an ID number: 12345678 → 12.345.678.
	 *
	 * @param string $digits Digits.
	 * @return string
	 */
	private static function dotted( $digits ) {
		return ltrim( strrev( implode( '.', str_split( strrev( $digits ), 3 ) ) ), '.' );
	}

	/**
	 * Brazil: CPF (11 digits) or CNPJ (14 characters, numeric or the alphanumeric format used from 2026).
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_br( $value, $strict = true ) {
		$id = self::alnum( $value );

		if ( preg_match( '/^\d{11}$/', $id ) ) {
			$formatted = substr( $id, 0, 3 ) . '.' . substr( $id, 3, 3 ) . '.' . substr( $id, 6, 3 ) . '-' . substr( $id, 9, 2 );
			$valid     = ! $strict || self::cpf_ok( $id );
			return self::result( $valid, 'cpf', false, $formatted );
		}

		if ( preg_match( '/^[A-Z0-9]{12}\d{2}$/', $id ) ) {
			$formatted = substr( $id, 0, 2 ) . '.' . substr( $id, 2, 3 ) . '.' . substr( $id, 5, 3 ) . '/' . substr( $id, 8, 4 ) . '-' . substr( $id, 12, 2 );
			$valid     = ! $strict || self::cnpj_ok( $id );
			return self::result( $valid, 'cnpj', true, $formatted );
		}

		return self::result( false, strlen( $id ) > 11 ? 'cnpj' : 'cpf', null, $id );
	}

	/**
	 * CPF check digits.
	 *
	 * @param string $id 11 digits.
	 * @return bool
	 */
	private static function cpf_ok( $id ) {
		if ( preg_match( '/^(\d)\1{10}$/', $id ) ) {
			return false;
		}
		foreach ( array( 9, 10 ) as $length ) {
			$sum = 0;
			for ( $i = 0; $i < $length; $i++ ) {
				$sum += (int) $id[ $i ] * ( $length + 1 - $i );
			}
			$digit = ( $sum * 10 ) % 11 % 10;
			if ( (int) $id[ $length ] !== $digit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * CNPJ check digits. Letters count as their ASCII code minus 48, as in the 2026 alphanumeric CNPJ.
	 *
	 * @param string $id 12 alphanumeric characters + 2 digits.
	 * @return bool
	 */
	private static function cnpj_ok( $id ) {
		if ( preg_match( '/^(.)\1{13}$/', $id ) ) {
			return false;
		}
		$weights = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
		foreach ( array( 12, 13 ) as $length ) {
			$sum    = 0;
			$offset = 13 - $length;
			for ( $i = 0; $i < $length; $i++ ) {
				$sum += ( ord( $id[ $i ] ) - 48 ) * $weights[ $i + $offset ];
			}
			$rest  = $sum % 11;
			$digit = $rest < 2 ? 0 : 11 - $rest;
			if ( (int) $id[ $length ] !== $digit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Paraguay: RUC (number-check digit) or CI (identity card number only).
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_py( $value, $strict = true ) {
		$id = strtoupper( preg_replace( '/[\s.]/', '', $value ) );

		if ( preg_match( '/^(\d{3,9})-(\d)$/', $id, $m ) ) {
			$valid = ! $strict || self::mod11_py( $m[1] ) === (int) $m[2];
			return self::result( $valid, 'ruc', 0 === strpos( $m[1], '80' ), $m[1] . '-' . $m[2] );
		}

		if ( preg_match( '/^\d{3,9}$/', $id ) ) {
			return self::result( true, 'ci', false, $id );
		}

		return self::result( false, 'ruc', null, $id );
	}

	/**
	 * Paraguayan RUC check digit (SET algorithm, base 11).
	 *
	 * @param string $base Digits before the dash.
	 * @return int
	 */
	private static function mod11_py( $base ) {
		$sum    = 0;
		$weight = 2;
		for ( $i = strlen( $base ) - 1; $i >= 0; $i-- ) {
			if ( $weight > 11 ) {
				$weight = 2;
			}
			$sum += (int) $base[ $i ] * $weight;
			++$weight;
		}
		$rest = $sum % 11;
		return $rest > 1 ? 11 - $rest : 0;
	}

	/**
	 * Argentina: CUIT/CUIL (11 digits) or DNI (7-8 digits).
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_ar( $value, $strict = true ) {
		$id = self::digits( $value );

		if ( 11 === strlen( $id ) ) {
			// A result of 10 is written as 9 (AFIP rule).
			$rest  = 11 - self::weighted( $id, array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 ) ) % 11;
			$digit = 11 === $rest ? 0 : min( $rest, 9 );
			$valid = ! $strict || (int) $id[10] === $digit;
			return self::result( $valid, 'cuit', in_array( substr( $id, 0, 2 ), array( '30', '33', '34' ), true ), substr( $id, 0, 2 ) . '-' . substr( $id, 2, 8 ) . '-' . $id[10] );
		}

		if ( preg_match( '/^\d{7,8}$/', $id ) ) {
			return self::result( true, 'dni', false, self::dotted( $id ) );
		}

		return self::result( false, 'cuit', null, $id );
	}

	/**
	 * Chile: RUT with check digit 0-9 or K.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_cl( $value, $strict = true ) {
		$id = self::alnum( $value );

		if ( ! preg_match( '/^(\d{6,9})([\dK])$/', $id, $m ) ) {
			return self::result( false, 'rut', null, $id );
		}

		$body = ltrim( $m[1], '0' );
		$valid = ! $strict || self::rut_digit( $body ) === $m[2];
		return self::result( $valid, 'rut', (int) $body >= 50000000, self::dotted( $body ) . '-' . $m[2] );
	}

	/**
	 * Chilean RUT check digit.
	 *
	 * @param string $body Digits before the check digit.
	 * @return string
	 */
	private static function rut_digit( $body ) {
		$sum    = 0;
		$weight = 2;
		for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
			$sum   += (int) $body[ $i ] * $weight;
			$weight = 7 === $weight ? 2 : $weight + 1;
		}
		$digit = 11 - $sum % 11;
		if ( 11 === $digit ) {
			return '0';
		}
		return 10 === $digit ? 'K' : (string) $digit;
	}

	/**
	 * Uruguay: RUT (12 digits) or CI (7-8 digits with check digit).
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_uy( $value, $strict = true ) {
		$id = self::digits( $value );

		if ( 12 === strlen( $id ) ) {
			$rest  = 11 - self::weighted( $id, array( 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ) ) % 11;
			$digit = 11 === $rest ? 0 : $rest;
			$valid = ! $strict || ( 10 !== $digit && (int) $id[11] === $digit );
			return self::result( $valid, 'rut', true, $id );
		}

		if ( preg_match( '/^\d{7,8}$/', $id ) ) {
			$padded = str_pad( $id, 8, '0', STR_PAD_LEFT );
			$digit  = ( 10 - self::weighted( $padded, array( 2, 9, 8, 7, 6, 3, 4 ) ) % 10 ) % 10;
			$valid  = ! $strict || (int) $padded[7] === $digit;
			$body   = substr( $id, 0, -1 );
			return self::result( $valid, 'ci', false, self::dotted( $body ) . '-' . substr( $id, -1 ) );
		}

		return self::result( false, 'rut', null, $id );
	}

	/**
	 * Peru: RUC (11 digits), DNI (8 digits) or a foreigner's card (9-12 characters).
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_pe( $value, $strict = true ) {
		$id = self::alnum( $value );

		if ( preg_match( '/^\d{11}$/', $id ) ) {
			$rest  = 11 - self::weighted( $id, array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 ) ) % 11;
			$digit = 10 === $rest ? 0 : ( 11 === $rest ? 1 : $rest );
			$valid = ! $strict || ( in_array( substr( $id, 0, 2 ), array( '10', '15', '16', '17', '20' ), true ) && (int) $id[10] === $digit );
			return self::result( $valid, 'ruc', '20' === substr( $id, 0, 2 ), $id );
		}

		if ( preg_match( '/^\d{8}$/', $id ) ) {
			return self::result( true, 'dni', false, $id );
		}

		if ( preg_match( '/^[A-Z0-9]{9,12}$/', $id ) ) {
			return self::result( true, 'other', false, $id );
		}

		return self::result( false, 'ruc', null, $id );
	}

	/**
	 * Colombia: NIT with check digit (900123456-7) or ID number without it.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_co( $value, $strict = true ) {
		$id = preg_replace( '/[\s.,]/', '', $value );

		if ( preg_match( '/^(\d{6,15})-(\d)$/', $id, $m ) ) {
			$weights = array( 3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71 );
			$sum     = 0;
			$base    = strrev( $m[1] );
			for ( $i = 0, $length = strlen( $base ); $i < $length; $i++ ) {
				$sum += (int) $base[ $i ] * $weights[ $i ];
			}
			$rest  = $sum % 11;
			$digit = $rest > 1 ? 11 - $rest : $rest;
			$valid = ! $strict || (int) $m[2] === $digit;
			return self::result( $valid, 'nit', 9 === strlen( $m[1] ) && in_array( $m[1][0], array( '8', '9' ), true ), self::dotted( $m[1] ) . '-' . $m[2] );
		}

		if ( preg_match( '/^\d{5,10}$/', $id ) ) {
			return self::result( true, 'cc', null, self::dotted( $id ) );
		}

		return self::result( false, 'nit', null, $id );
	}

	/**
	 * Ecuador: cédula (10 digits) or RUC (13 digits).
	 *
	 * Company RUCs (third digit 6 or 9) are only checked for shape: some numbers issued by the SRI
	 * do not follow the old check digit rule, and rejecting a real customer is worse than accepting a typo.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_ec( $value, $strict = true ) {
		$id = self::digits( $value );

		if ( 10 === strlen( $id ) ) {
			return self::result( ! $strict || self::cedula_ec_ok( $id ), 'ci', false, $id );
		}

		if ( 13 === strlen( $id ) ) {
			$third = (int) $id[2];
			if ( $third < 6 ) {
				$valid = ! $strict || ( self::cedula_ec_ok( substr( $id, 0, 10 ) ) && '000' !== substr( $id, 10 ) );
				return self::result( $valid, 'ruc', false, $id );
			}
			$valid = in_array( $third, array( 6, 9 ), true ) && self::province_ec_ok( $id );
			return self::result( $valid, 'ruc', true, $id );
		}

		return self::result( false, 'ruc', null, $id );
	}

	/**
	 * Ecuadorian province code (first two digits).
	 *
	 * @param string $id Digits.
	 * @return bool
	 */
	private static function province_ec_ok( $id ) {
		$province = (int) substr( $id, 0, 2 );
		return ( $province >= 1 && $province <= 24 ) || 30 === $province;
	}

	/**
	 * Ecuadorian cédula check digit (modulus 10).
	 *
	 * @param string $id 10 digits.
	 * @return bool
	 */
	private static function cedula_ec_ok( $id ) {
		if ( ! self::province_ec_ok( $id ) || (int) $id[2] > 5 ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$product = (int) $id[ $i ] * ( 0 === $i % 2 ? 2 : 1 );
			$sum    += $product > 9 ? $product - 9 : $product;
		}
		return (int) $id[9] === ( 10 - $sum % 10 ) % 10;
	}

	/**
	 * Mexico: RFC (12 characters for companies, 13 for people). Shape and date only:
	 * the SAT has issued RFCs whose last character does not match the published algorithm.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Unused (shape check only).
	 * @return array
	 */
	public static function check_mx( $value, $strict = true ) {
		$id = strtoupper( preg_replace( '/[\s\-]/', '', $value ) );

		if ( preg_match( '/^([A-ZÑ&]{3,4})(\d{2})(\d{2})(\d{2})[A-Z0-9]{3}$/u', $id, $m ) ) {
			$month = (int) $m[3];
			$day   = (int) $m[4];
			$valid = $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31;
			return self::result( $valid, 'rfc', 3 === mb_strlen( $m[1] ), $id );
		}

		return self::result( false, 'rfc', null, $id );
	}

	/**
	 * Spain: NIF (DNI), NIE or CIF, with or without the ES prefix.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_es( $value, $strict = true ) {
		$id      = preg_replace( '/^ES/', '', self::alnum( $value ) );
		$letters = 'TRWAGMYFPDXBNJZSQVHLCKE';

		if ( preg_match( '/^(\d{8})([A-Z])$/', $id, $m ) ) {
			$valid = ! $strict || $letters[ (int) $m[1] % 23 ] === $m[2];
			return self::result( $valid, 'nif', false, $id );
		}

		if ( preg_match( '/^([XYZ])(\d{7})([A-Z])$/', $id, $m ) ) {
			$number = (int) ( strpos( 'XYZ', $m[1] ) . $m[2] );
			$valid  = ! $strict || $letters[ $number % 23 ] === $m[3];
			return self::result( $valid, 'nie', false, $id );
		}

		if ( preg_match( '/^([KLM])(\d{7})([A-Z])$/', $id, $m ) ) {
			$valid = ! $strict || $letters[ (int) $m[2] % 23 ] === $m[3];
			return self::result( $valid, 'nif', false, $id );
		}

		if ( preg_match( '/^([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])$/', $id, $m ) ) {
			$sum = 0;
			for ( $i = 0; $i < 7; $i++ ) {
				$digit = (int) $m[2][ $i ];
				if ( 0 === $i % 2 ) {
					$digit *= 2;
					$digit  = intdiv( $digit, 10 ) + $digit % 10;
				}
				$sum += $digit;
			}
			$control = ( 10 - $sum % 10 ) % 10;
			$letter  = 'JABCDEFGHI'[ $control ];
			if ( false !== strpos( 'PQRSNW', $m[1] ) ) {
				$ok = $m[3] === $letter;
			} elseif ( false !== strpos( 'ABEH', $m[1] ) ) {
				$ok = $m[3] === (string) $control;
			} else {
				$ok = $m[3] === (string) $control || $m[3] === $letter;
			}
			return self::result( ! $strict || $ok, 'cif', true, $id );
		}

		return self::result( false, 'nif', null, $id );
	}

	/**
	 * Portugal: NIF (9 digits), with or without the PT prefix.
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check digits.
	 * @return array
	 */
	public static function check_pt( $value, $strict = true ) {
		$id = preg_replace( '/^PT/', '', self::alnum( $value ) );

		if ( ! preg_match( '/^[1-35689]\d{8}$/', $id ) ) {
			return self::result( false, 'nif', null, $id );
		}

		$rest  = self::weighted( $id, array( 9, 8, 7, 6, 5, 4, 3, 2 ) ) % 11;
		$digit = $rest < 2 ? 0 : 11 - $rest;
		return self::result( ! $strict || (int) $id[8] === $digit, 'nif', in_array( $id[0], array( '5', '6', '9' ), true ), $id );
	}

	/**
	 * Italy: codice fiscale (16 characters) or partita IVA (11 digits).
	 *
	 * @param string $value  Value.
	 * @param bool   $strict Check characters.
	 * @return array
	 */
	public static function check_it( $value, $strict = true ) {
		$id = preg_replace( '/^IT(?=\d{11}$)/', '', self::alnum( $value ) );

		if ( preg_match( '/^\d{11}$/', $id ) ) {
			$sum = 0;
			for ( $i = 0; $i < 10; $i++ ) {
				$digit = (int) $id[ $i ];
				if ( 1 === $i % 2 ) {
					$digit *= 2;
					$digit  = $digit > 9 ? $digit - 9 : $digit;
				}
				$sum += $digit;
			}
			return self::result( ! $strict || (int) $id[10] === ( 10 - $sum % 10 ) % 10, 'piva', true, $id );
		}

		if ( preg_match( '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $id ) ) {
			return self::result( ! $strict || self::cf_char( $id ) === $id[15], 'cf', false, $id );
		}

		return self::result( false, 'cf', null, $id );
	}

	/**
	 * Codice fiscale check character.
	 *
	 * @param string $id 16 characters.
	 * @return string
	 */
	private static function cf_char( $id ) {
		$odd = array( 1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23 );
		$sum = 0;
		for ( $i = 0; $i < 15; $i++ ) {
			$char  = $id[ $i ];
			$index = ctype_digit( $char ) ? (int) $char : ord( $char ) - 65;
			$sum  += 0 === $i % 2 ? $odd[ $index ] : $index;
		}
		return chr( 65 + $sum % 26 );
	}

	/**
	 * VAT number shape for the EU, the UK, Switzerland and Norway. The prefix is optional.
	 *
	 * @param string $country Country code.
	 * @param string $value   Value.
	 * @return array
	 */
	public static function check_vat( $country, $value ) {
		$id     = self::alnum( $value );
		$prefix = 'GR' === $country ? 'EL' : ( 'CH' === $country ? 'CH' : $country );

		if ( 0 === strpos( $id, $prefix ) || ( 'GR' === $country && 0 === strpos( $id, 'GR' ) ) ) {
			$id = substr( $id, 2 );
		}

		$valid = (bool) preg_match( '/^(' . self::VAT_FORMATS[ $country ] . ')$/', $id );
		return self::result( $valid, 'vat', true, $prefix . $id );
	}

	/**
	 * Any other country: 3 to 30 letters, digits and the usual separators.
	 *
	 * @param string $value Value.
	 * @return array
	 */
	public static function check_other( $value ) {
		$id    = strtoupper( trim( $value ) );
		$valid = (bool) preg_match( '/^[A-Z0-9][A-Z0-9 .\-\/]{1,28}[A-Z0-9]$/', $id ) && strlen( self::alnum( $id ) ) >= 3;
		return self::result( $valid, 'other', null, $id );
	}
}
