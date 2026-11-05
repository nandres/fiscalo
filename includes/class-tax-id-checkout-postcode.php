<?php
/**
 * Address lookup by postcode.
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint GET /wp-json/tax-id-checkout/v1/postcode?country=BR&postcode=01001000
 *
 * Brazil: ViaCEP (BrasilAPI as backup) returns street, neighborhood, city and state.
 * Other countries supported by Zippopotam.us return city and state.
 * Answers are cached for 30 days (misses for 1 day).
 */
class Tax_ID_Checkout_Postcode {

	const ROUTE_NAMESPACE = 'tax-id-checkout/v1';

	/**
	 * Countries answered by Zippopotam.us (the UK and Canada are left out: only part of their postcodes is covered).
	 *
	 * @var string[]
	 */
	const ZIPPOPOTAM = array( 'AD', 'AR', 'AS', 'AT', 'AU', 'BD', 'BE', 'BG', 'CH', 'CZ', 'DE', 'DK', 'DO', 'ES', 'FI', 'FO', 'FR', 'GF', 'GG', 'GL', 'GP', 'GT', 'GU', 'HR', 'HU', 'IM', 'IN', 'IS', 'IT', 'JE', 'JP', 'LI', 'LK', 'LT', 'LU', 'MC', 'MD', 'MH', 'MK', 'MP', 'MQ', 'MX', 'MY', 'NL', 'NO', 'NZ', 'PH', 'PK', 'PL', 'PM', 'PR', 'PT', 'RE', 'SE', 'SI', 'SJ', 'SK', 'SM', 'TH', 'TR', 'US', 'VA', 'VI', 'YT', 'ZA' );

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Countries with postcode lookup.
	 *
	 * @return string[]
	 */
	public static function countries() {
		return array_merge( array( 'BR' ), self::ZIPPOPOTAM );
	}

	/**
	 * Register the route.
	 */
	public static function routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/postcode',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'respond' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'country'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static function ( $value ) {
							return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 2 ) );
						},
					),
					'postcode' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static function ( $value ) {
							return strtoupper( substr( preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) $value ), 0, 12 ) );
						},
					),
				),
			)
		);
	}

	/**
	 * REST callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function respond( $request ) {
		if ( ! Tax_ID_Checkout_Settings::enabled( 'postcode_autofill' ) ) {
			return new WP_REST_Response( array( 'found' => false ), 200 );
		}
		$address = self::lookup( $request['country'], $request['postcode'] );
		return new WP_REST_Response( $address ? array( 'found' => true ) + $address : array( 'found' => false ), 200 );
	}

	/**
	 * Find the address for a postcode.
	 *
	 * @param string $country  Country code.
	 * @param string $postcode Postcode.
	 * @return array|null address_1, neighborhood, city, state (WooCommerce code) — empty strings when unknown.
	 */
	public static function lookup( $country, $postcode ) {
		$postcode = trim( $postcode );

		/**
		 * Short-circuits the lookup (return an array, or false for "not found").
		 *
		 * @param array|false|null $address  Address, or null to look it up.
		 * @param string           $country  Country code.
		 * @param string           $postcode Postcode.
		 */
		$pre = apply_filters( 'tax_id_checkout_postcode_lookup', null, $country, $postcode );
		if ( null !== $pre ) {
			return $pre ? $pre : null;
		}

		if ( 'BR' === $country ) {
			$postcode = preg_replace( '/\D/', '', $postcode );
			if ( 8 !== strlen( $postcode ) ) {
				return null;
			}
		} elseif ( ! in_array( $country, self::ZIPPOPOTAM, true ) || strlen( preg_replace( '/[^A-Z0-9]/', '', $postcode ) ) < 3 ) {
			return null;
		}

		$cache_key = 'tax_id_checkout_pc_' . md5( $country . '|' . $postcode );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$address = 'BR' === $country ? self::brazil( $postcode ) : self::zippopotam( $country, $postcode );

		set_transient( $cache_key, $address ? $address : 0, $address ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );
		return $address;
	}

	/**
	 * GET a JSON document. Hosts are fixed in this class (only the postcode comes from the visitor).
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private static function get_json( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 6,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Brazil: ViaCEP, then BrasilAPI.
	 *
	 * @param string $cep 8 digits.
	 * @return array|null
	 */
	private static function brazil( $cep ) {
		$data = self::get_json( 'https://viacep.com.br/ws/' . $cep . '/json/' );
		if ( $data && empty( $data['erro'] ) && ! empty( $data['localidade'] ) ) {
			return self::address( $data['logradouro'] ?? '', $data['bairro'] ?? '', $data['localidade'], self::state_code( 'BR', $data['uf'] ?? '' ) );
		}

		$data = self::get_json( 'https://brasilapi.com.br/api/cep/v1/' . $cep );
		if ( $data && ! empty( $data['city'] ) ) {
			return self::address( $data['street'] ?? '', $data['neighborhood'] ?? '', $data['city'], self::state_code( 'BR', $data['state'] ?? '' ) );
		}

		return null;
	}

	/**
	 * Zippopotam.us: city and state.
	 *
	 * @param string $country  Country code.
	 * @param string $postcode Postcode.
	 * @return array|null
	 */
	private static function zippopotam( $country, $postcode ) {
		$candidates = array( str_replace( ' ', '', $postcode ) );
		if ( false !== strpos( $postcode, ' ' ) ) {
			$candidates[] = strtok( $postcode, ' ' );
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$data = self::get_json( 'https://api.zippopotam.us/' . strtolower( $country ) . '/' . rawurlencode( $candidate ) );
			if ( $data && ! empty( $data['places'][0] ) ) {
				$place = $data['places'][0];
				$name  = $place['place name'] ?? '';
				$state = self::state_code( $country, $place['state abbreviation'] ?? '', $place['state'] ?? '', $name );

				// In Mexico the "place" is the colonia, not the city.
				if ( 'MX' === $country ) {
					return self::address( '', $name, 'DF' === $state ? 'Ciudad de México' : '', $state );
				}
				if ( '' !== $name ) {
					return self::address( '', '', $name, $state );
				}
			}
		}
		return null;
	}

	/**
	 * Build the answer.
	 *
	 * @param string $street       Street.
	 * @param string $neighborhood Neighborhood.
	 * @param string $city         City.
	 * @param string $state        WooCommerce state code.
	 * @return array
	 */
	private static function address( $street, $neighborhood, $city, $state ) {
		return array(
			'address_1'    => sanitize_text_field( $street ),
			'neighborhood' => sanitize_text_field( $neighborhood ),
			'city'         => sanitize_text_field( $city ),
			'state'        => sanitize_text_field( $state ),
		);
	}

	/**
	 * WooCommerce state code for what a service returned, or '' when the country has no state list or nothing matches.
	 *
	 * Tries the code, then the name (also "Veracruz de Ignacio de la Llave" → Veracruz), then the place name
	 * (Italian and Spanish services return regions, while WooCommerce lists provinces named after their capital).
	 *
	 * @param string $country    Country code.
	 * @param string $state      State code from the service.
	 * @param string $state_name State name from the service.
	 * @param string $place      Place name from the service.
	 * @return string
	 */
	public static function state_code( $country, $state, $state_name = '', $place = '' ) {
		$states = WC()->countries->get_states( $country );
		if ( empty( $states ) ) {
			return '';
		}

		$aliases = array(
			'MX' => array(
				'distritofederal' => 'DF',
				'ciudaddemexico'  => 'DF',
				'mexico'          => 'MX',
			),
		);
		$state   = strtoupper( trim( $state ) );

		if ( '' !== $state && isset( $states[ $state ] ) ) {
			return $state;
		}
		if ( '' !== $state && isset( $states[ $country . '-' . $state ] ) ) {
			return $country . '-' . $state;
		}

		$name = self::plain( $state_name );
		if ( isset( $aliases[ $country ][ $name ] ) ) {
			return $aliases[ $country ][ $name ];
		}

		foreach ( array( $name, self::plain( $state ), self::plain( $place ) ) as $wanted ) {
			if ( strlen( $wanted ) < 4 ) {
				continue;
			}
			foreach ( $states as $code => $state_label ) {
				$label = self::plain( $state_label );
				if ( $label === $wanted ) {
					return (string) $code;
				}
				// Long official names: "Veracruz de Ignacio de la Llave" starts with "Veracruz".
				if ( $wanted === $name && strlen( $label ) >= 4 && 0 === strpos( $wanted, $label ) ) {
					return (string) $code;
				}
			}
		}
		return '';
	}

	/**
	 * Lowercase without accents or punctuation, for comparing names.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( $text ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( html_entity_decode( (string) $text ) ) ) );
	}
}
