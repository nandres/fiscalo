<?php
/**
 * Settings storage and settings screen (WooCommerce → Fiscalo).
 *
 * @package TaxIDCheckout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores all options in a single `tax_id_checkout_settings` array.
 */
class Tax_ID_Checkout_Settings {

	const OPTION = 'tax_id_checkout_settings';
	const PAGE   = 'fiscalo';

	/**
	 * Cached settings for the request.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_import' ) );
		add_filter( 'option_page_capability_' . self::PAGE, array( __CLASS__, 'capability' ) );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Settings sections, filterable so Pro can add its own.
	 *
	 * @return array<string,array{title:string,description?:string}>
	 */
	public static function sections() {
		$sections = array(
			'taxid'   => array(
				'title'       => __( 'Tax ID', 'fiscalo' ),
				'description' => __( 'One field that changes with the billing country: CPF or CNPJ in Brazil, RUC in Paraguay, CUIT in Argentina, RUT in Chile, RFC in Mexico, NIF in Spain, VAT number in Europe and "Tax ID" everywhere else.', 'fiscalo' ),
			),
			'address' => array(
				'title'       => __( 'Address', 'fiscalo' ),
				'description' => __( 'Extra address fields for the countries where couriers need them.', 'fiscalo' ),
			),
		);

		/**
		 * Filters the settings sections.
		 *
		 * @param array $sections Sections keyed by id.
		 */
		return apply_filters( 'tax_id_checkout_settings_sections', $sections );
	}

	/**
	 * Settings fields, filterable so Pro can add its own.
	 *
	 * @return array<string,array> Fields keyed by option key.
	 */
	public static function fields() {
		$fields = array(
			'taxid_countries'          => array(
				'section'     => 'taxid',
				'type'        => 'countries',
				'label'       => __( 'Ask in these countries', 'fiscalo' ),
				'description' => __( 'Leave empty to ask in every country.', 'fiscalo' ),
				'default'     => array(),
			),
			'taxid_mode'               => array(
				'section' => 'taxid',
				'type'    => 'select',
				'label'   => __( 'When to ask', 'fiscalo' ),
				'options' => array(
					'always'  => __( 'Always', 'fiscalo' ),
					'invoice' => __( 'Only when the customer ticks "I need an invoice"', 'fiscalo' ),
				),
				'default' => 'always',
			),
			'invoice_label'            => array(
				'section'     => 'taxid',
				'type'        => 'text',
				'label'       => __( 'Invoice checkbox text', 'fiscalo' ),
				'description' => __( 'Used when the tax ID is only asked for invoices.', 'fiscalo' ),
				'default'     => __( 'I need an invoice', 'fiscalo' ),
			),
			'taxid_required'           => array(
				'section' => 'taxid',
				'type'    => 'select',
				'label'   => __( 'Required', 'fiscalo' ),
				'options' => array(
					'no'        => __( 'No, optional', 'fiscalo' ),
					'yes'       => __( 'Yes, in every country', 'fiscalo' ),
					'countries' => __( 'Only in the countries below', 'fiscalo' ),
				),
				'default' => 'no',
			),
			'taxid_required_countries' => array(
				'section' => 'taxid',
				'type'    => 'countries',
				'label'   => __( 'Required in', 'fiscalo' ),
				'default' => array(),
			),
			'validation'               => array(
				'section'     => 'taxid',
				'type'        => 'select',
				'label'       => __( 'Validation', 'fiscalo' ),
				'options'     => array(
					'strict' => __( 'Check digits (recommended)', 'fiscalo' ),
					'format' => __( 'Format only', 'fiscalo' ),
					'off'    => __( 'Off', 'fiscalo' ),
				),
				'description' => __( 'Check digits catch typos in CPF, CNPJ, RUC, CUIT, RUT, NIT, NIF, Codice fiscale and more. Countries without a public rule are checked for format only.', 'fiscalo' ),
				'default'     => 'strict',
			),
			'number_countries'         => array(
				'section'     => 'address',
				'type'        => 'countries',
				'label'       => __( 'House number field', 'fiscalo' ),
				'description' => __( 'Countries where the house number gets its own field. Leave empty to never show it.', 'fiscalo' ),
				'default'     => array( 'BR' ),
			),
			'number_required'          => array(
				'section'        => 'address',
				'type'           => 'checkbox',
				'label'          => __( 'House number required', 'fiscalo' ),
				'checkbox_label' => __( 'Required (customers can type "S/N" when there is none)', 'fiscalo' ),
				'default'        => 'yes',
			),
			'neighborhood_countries'   => array(
				'section'     => 'address',
				'type'        => 'countries',
				'label'       => __( 'Neighborhood field', 'fiscalo' ),
				'description' => __( 'Countries where the neighborhood (bairro, barrio, colonia) gets its own field.', 'fiscalo' ),
				'default'     => array( 'BR' ),
			),
			'neighborhood_required'    => array(
				'section'        => 'address',
				'type'           => 'checkbox',
				'label'          => __( 'Neighborhood required', 'fiscalo' ),
				'checkbox_label' => __( 'Required', 'fiscalo' ),
				'default'        => 'yes',
			),
			'postcode_autofill'        => array(
				'section'        => 'address',
				'type'           => 'checkbox',
				'label'          => __( 'Postcode autofill', 'fiscalo' ),
				'checkbox_label' => __( 'Fill in the address from the postcode', 'fiscalo' ),
				'description'    => __( 'Brazil: street, neighborhood, city and state (ViaCEP, with BrasilAPI as backup). About 60 other countries: city and state (Zippopotam.us). Only the postcode and country are sent, and answers are cached on your site.', 'fiscalo' ),
				'default'        => 'yes',
			),
		);

		/**
		 * Filters the settings fields.
		 *
		 * @param array $fields Fields keyed by option key.
		 */
		return apply_filters( 'tax_id_checkout_settings_fields', $fields );
	}

	/**
	 * Get a setting value (falls back to the field default).
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( null === self::$settings ) {
			$saved          = get_option( self::OPTION, array() );
			self::$settings = is_array( $saved ) ? $saved : array();
		}

		if ( array_key_exists( $key, self::$settings ) ) {
			return self::$settings[ $key ];
		}

		$fields = self::fields();
		return isset( $fields[ $key ]['default'] ) ? $fields[ $key ]['default'] : '';
	}

	/**
	 * Whether a checkbox setting is on.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function enabled( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Drop the request cache after the option changes.
	 */
	public static function flush_cache() {
		self::$settings = null;
	}

	/**
	 * Shop managers can edit these settings too.
	 *
	 * @return string
	 */
	public static function capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Let WooCommerce load its admin styles and country selector on the settings screen.
	 *
	 * @param string[] $ids Screen ids.
	 * @return string[]
	 */
	public static function screen_ids( $ids ) {
		$ids[] = 'woocommerce_page_' . self::PAGE;
		return $ids;
	}

	/**
	 * Add WooCommerce → Fiscalo.
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Fiscalo', 'fiscalo' ),
			__( 'Fiscalo', 'fiscalo' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Start from the settings of the Brazilian Market plugin when it was used before.
	 */
	public static function maybe_import() {
		if ( false !== get_option( self::OPTION ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$old = get_option( 'wcbcf_settings' );
		if ( ! is_array( $old ) ) {
			return;
		}

		$person_type = isset( $old['person_type'] ) ? (int) $old['person_type'] : 1;
		update_option(
			self::OPTION,
			array(
				'taxid_countries'          => array( 'BR' ),
				'taxid_required'           => $person_type ? 'countries' : 'no',
				'taxid_required_countries' => array( 'BR' ),
				'number_countries'         => array( 'BR' ),
				'neighborhood_countries'   => array( 'BR' ),
				'neighborhood_required'    => empty( $old['neighborhood_required'] ) ? 'no' : 'yes',
			)
		);
		update_option( 'tax_id_checkout_imported', 'wcbcf', false );
	}

	/**
	 * Register the option, sections and fields with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		foreach ( self::sections() as $id => $section ) {
			add_settings_section(
				'tax_id_checkout_' . $id,
				$section['title'],
				static function () use ( $section ) {
					if ( ! empty( $section['description'] ) ) {
						echo '<p>' . wp_kses_post( $section['description'] ) . '</p>';
					}
				},
				self::PAGE
			);
		}

		foreach ( self::fields() as $key => $field ) {
			add_settings_field(
				'tax_id_checkout_' . $key,
				esc_html( $field['label'] ),
				array( __CLASS__, 'render_field' ),
				self::PAGE,
				'tax_id_checkout_' . $field['section'],
				array(
					'key'       => $key,
					'field'     => $field,
					'label_for' => 'checkbox' === $field['type'] ? '' : 'tax-id-checkout-' . $key,
				)
			);
		}
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		// Keep values of fields that are not registered right now (e.g. Pro settings while Pro is inactive).
		$saved = get_option( self::OPTION, array() );
		$clean = is_array( $saved ) ? array_diff_key( $saved, self::fields() ) : array();

		foreach ( self::fields() as $key => $field ) {
			$value = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';

			switch ( $field['type'] ) {
				case 'checkbox':
					$clean[ $key ] = 'yes' === $value ? 'yes' : 'no';
					break;
				case 'countries':
					$known         = array_keys( WC()->countries->get_countries() );
					$clean[ $key ] = array_values( array_intersect( array_map( 'strtoupper', (array) $value ), $known ) );
					break;
				case 'multiselect':
					$clean[ $key ] = array_values( array_intersect( array_map( 'strval', (array) $value ), array_map( 'strval', array_keys( $field['options'] ) ) ) );
					break;
				case 'number':
					$clean[ $key ] = '' === $value ? '' : (string) max( 0, (float) str_replace( ',', '.', $value ) );
					break;
				case 'select':
					$clean[ $key ] = array_key_exists( $value, $field['options'] ) ? $value : $field['default'];
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $value );
			}

			if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
				$clean[ $key ] = call_user_func( $field['sanitize'], $clean[ $key ], $value );
			}
		}

		return $clean;
	}

	/**
	 * Render a single field.
	 *
	 * @param array $args Field args.
	 */
	public static function render_field( $args ) {
		$key   = $args['key'];
		$field = $args['field'];
		$id    = 'tax-id-checkout-' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = self::get( $key );

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="yes" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 'yes', $value, false ),
					esc_html( isset( $field['checkbox_label'] ) ? $field['checkbox_label'] : __( 'Enabled', 'fiscalo' ) )
				);
				break;
			case 'countries':
				printf(
					'<select id="%1$s" name="%2$s[]" multiple="multiple" class="wc-enhanced-select" style="min-width:350px;" data-placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr__( 'Choose countries…', 'fiscalo' )
				);
				foreach ( WC()->countries->get_countries() as $code => $country ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $code ), selected( in_array( $code, (array) $value, true ), true, false ), esc_html( $country ) );
				}
				echo '</select>';
				break;
			case 'multiselect':
				printf(
					'<select id="%1$s" name="%2$s[]" multiple="multiple" class="wc-enhanced-select" style="min-width:350px;" data-placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr__( 'Choose…', 'fiscalo' )
				);
				foreach ( $field['options'] as $option_value => $option_label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option_value ), selected( in_array( (string) $option_value, array_map( 'strval', (array) $value ), true ), true, false ), esc_html( $option_label ) );
				}
				echo '</select>';
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $option_value => $option_label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option_value ), selected( $option_value, $value, false ), esc_html( $option_label ) );
				}
				echo '</select>';
				break;
			default:
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s" placeholder="%6$s" %7$s />',
					'number' === $field['type'] ? 'number' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					'number' === $field['type'] ? 'small-text' : 'regular-text',
					esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ),
					'number' === $field['type'] ? 'min="0" step="any"' : ''
				);
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $field['description'] ) . '</p>';
		}
	}

	/**
	 * Render the settings screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap tax-id-checkout-settings">
			<h1><?php esc_html_e( 'Fiscalo', 'fiscalo' ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<?php
			/**
			 * Fires after the settings form (used for the Pro upgrade box).
			 */
			do_action( 'tax_id_checkout_settings_page_after' );
			?>
		</div>
		<?php
	}
}
