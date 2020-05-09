<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/sofyansitorus
 * @since      1.0.0
 *
 * @package    Woongkir
 * @subpackage Woongkir/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Woongkir
 * @subpackage Woongkir/includes
 * @author     Sofyan Sitorus <sofyansitorus@gmail.com>
 */
class Woongkir {


	/**
	 * Shipping base country
	 *
	 * @var string
	 */
	private $base_country = 'ID';

	/**
	 * Hold an instance of the class
	 *
	 * @var Woongkir
	 */
	private static $instance = null;

	/**
	 * The object is created from within the class itself
	 * only if the class has no instance.
	 *
	 * @return Woongkir
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new Woongkir();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		// Hook to load plugin textdomain.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Hook to add plugin action links.
		add_action( 'plugin_action_links_' . plugin_basename( WOONGKIR_FILE ), array( $this, 'plugin_action_links' ) );

		// Hook to enqueue scripts & styles assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_backend_assets' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 999 );

		// Hook to check if this shipping method is available for current order.
		add_filter( 'woocommerce_shipping_' . WOONGKIR_METHOD_ID . '_is_available', array( $this, 'check_is_available' ), 10, 2 );

		// Hook to modify billing and shipping address fields position priority.
		add_filter( 'woocommerce_default_address_fields', array( $this, 'default_address_fields_priority' ) );
		add_filter( 'woocommerce_billing_fields', array( $this, 'billing_fields_priority' ), 10, 2 );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'shipping_fields_priority' ), 10, 2 );

		// Hook to woocommerce_cart_shipping_packages to inject field address_2.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'inject_cart_shipping_packages' ), 10 );

		// Hook to  print hidden element for the hidden address 2 field after the shipping calculator form.
		add_action( 'woocommerce_after_shipping_calculator', array( $this, 'after_shipping_calculator' ) );

		// Hook to enable city field in the shipping calculator form.
		add_filter( 'woocommerce_shipping_calculator_enable_city', '__return_true' );

		// Hook to register the shipping method.
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
	}

	/**
	 * Check if this method available
	 *
	 * @since 1.0.0
	 * @param boolean $available Current status is available.
	 * @param array   $package Current order package data.
	 * @return bool
	 */
	public function check_is_available( $available, $package ) {
		if ( WC()->countries->get_base_country() !== $this->base_country ) {
			return false;
		}

		if ( empty( $package ) || empty( $package['contents'] ) || empty( $package['destination'] ) ) {
			return false;
		}

		return $available;
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'woongkir', false, basename( WOONGKIR_PATH ) . '/languages' );
	}

	/**
	 * Add plugin action links.
	 *
	 * Add a link to the settings page on the plugins.php page.
	 *
	 * @since 1.1.3
	 *
	 * @param  array $links List of existing plugin action links.
	 * @return array         List of modified plugin action links.
	 */
	public function plugin_action_links( $links ) {
		$zone_id = 0;

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $links;
		}

		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			if ( empty( $zone['shipping_methods'] ) || empty( $zone['zone_id'] ) ) {
				continue;
			}

			foreach ( $zone['shipping_methods'] as $zone_shipping_method ) {
				if ( $zone_shipping_method instanceof Woongkir ) {
					$zone_id = $zone['zone_id'];
					break;
				}
			}

			if ( $zone_id ) {
				break;
			}
		}

		$links = array_merge(
			array(
				'<a href="' . esc_url(
					add_query_arg(
						array(
							'page'              => 'wc-settings',
							'tab'               => 'shipping',
							'zone_id'           => $zone_id,
							'woongkir_settings' => true,
						),
						admin_url( 'admin.php' )
					)
				) . '">' . __( 'Settings', 'woongkir' ) . '</a>',
			),
			$links
		);

		return $links;
	}

	/**
	 * Enqueue backend scripts.
	 *
	 * @since 1.0.0
	 * @param string $hook Passed screen ID in admin area.
	 */
	public function enqueue_backend_assets( $hook = null ) {
		if ( ! is_admin() ) {
			return;
		}

		$is_dev_env = woongkir_is_dev();

		if ( 'woocommerce_page_wc-settings' === $hook ) {
			// Define the styles URL.
			$css_url = WOONGKIR_URL . 'assets/css/woongkir-backend.min.css';
			if ( $is_dev_env ) {
				$css_url = add_query_arg( array( 't' => time() ), str_replace( '.min', '', $css_url ) );
			}

			// Enqueue admin styles.
			wp_enqueue_style(
				'woongkir-backend', // Give the script a unique ID.
				$css_url, // Define the path to the JS file.
				array(), // Define dependencies.
				woongkir_get_plugin_data( 'Version' ), // Define a version (optional).
				false // Specify whether to put in footer (leave this false).
			);

			// Register lockr.js scripts.
			$lockr_url = WOONGKIR_URL . 'assets/js/lockr.min.js';
			if ( $is_dev_env ) {
				$lockr_url = add_query_arg( array( 't' => time() ), str_replace( '.min', '', $lockr_url ) );
			}

			wp_register_script(
				'lockr.js', // Give the script a unique ID.
				$lockr_url, // Define the path to the JS file.
				array( 'jquery' ), // Define dependencies.
				woongkir_get_plugin_data( 'Version' ), // Define a version (optional).
				true // Specify whether to put in footer (leave this true).
			);

			// Define the scripts URL.
			$js_url = WOONGKIR_URL . 'assets/js/woongkir-backend.min.js';
			if ( $is_dev_env ) {
				$js_url = add_query_arg( array( 't' => time() ), str_replace( '.min', '', $js_url ) );
			}

			wp_enqueue_script(
				'woongkir-backend', // Give the script a unique ID.
				$js_url, // Define the path to the JS file.
				array( 'jquery', 'accordion', 'wp-util', 'selectWoo', 'lockr.js' ), // Define dependencies.
				woongkir_get_plugin_data( 'Version' ), // Define a version (optional).
				true // Specify whether to put in footer (leave this true).
			);

			wp_localize_script( 'woongkir-backend', 'woongkir_params', woongkir_scripts_params() );
		}
	}

	/**
	 * Enqueue frontend scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_frontend_assets() {
		if ( is_admin() || ! woongkir_instances() ) {
			return;
		}

		$is_dev_env = woongkir_is_dev();

		// Register lockr.js scripts.
		$lockr_url = WOONGKIR_URL . 'assets/js/lockr.min.js';
		if ( $is_dev_env ) {
			$lockr_url = add_query_arg( array( 't' => time() ), str_replace( '.min', '', $lockr_url ) );
		}

		wp_register_script(
			'lockr.js', // Give the script a unique ID.
			$lockr_url, // Define the path to the JS file.
			array(), // Define dependencies.
			woongkir_get_plugin_data( 'Version' ), // Define a version (optional).
			true // Specify whether to put in footer (leave this true).
		);

		// Enqueue main scripts.
		$js_url = WOONGKIR_URL . 'assets/js/woongkir-frontend.min.js';
		if ( $is_dev_env ) {
			$js_url = add_query_arg( array( 't' => time() ), str_replace( '.min', '', $js_url ) );
		}

		wp_enqueue_script(
			'woongkir-frontend', // Give the script a unique ID.
			$js_url, // Define the path to the JS file.
			array( 'jquery', 'wp-util', 'selectWoo', 'lockr.js' ), // Define dependencies.
			woongkir_get_plugin_data( 'Version' ), // Define a version (optional).
			true // Specify whether to put in footer (leave this true).
		);

		wp_localize_script( 'woongkir-frontend', 'woongkir_params', woongkir_scripts_params() );
	}

	/**
	 * Modify default address fields priority.
	 *
	 * @param array $fields Address fields used by default.
	 */
	public function default_address_fields_priority( $fields ) {
		if ( isset( $fields['state'] ) ) {
			$fields['state']['priority'] = 41;
		}

		if ( isset( $fields['city'] ) ) {
			$fields['city']['priority'] = 42;
		}

		return $fields;
	}

	/**
	 * Modify billing fields priority.
	 *
	 * @since 1.0.0
	 * @param array  $fields Address fields used by default.
	 * @param string $country Selected country.
	 */
	public function billing_fields_priority( $fields, $country ) {
		if ( 'ID' !== $country ) {
			return $fields;
		}

		$need_sort = false;

		if ( isset( $fields['billing_state'] ) ) {
			$fields['billing_state']['priority'] = 41;
			$need_sort                           = true;
		}

		if ( isset( $fields['billing_city'] ) ) {
			$fields['billing_city']['priority'] = 42;
			$need_sort                          = true;
		}

		if ( ! $need_sort ) {
			return $fields;
		}

		$priority_offset = count( $fields ) * 10;
		$billing_fields  = array();

		foreach ( $fields as $key => $value ) {
			$billing_fields[ $key ] = isset( $value['priority'] ) ? $value['priority'] : $priority_offset;
			$priority_offset       += 10;
		}

		// Sort fields by priority.
		asort( $billing_fields );

		$billing_field_keys = array_keys( $billing_fields );

		foreach ( $billing_field_keys as $billing_field_key ) {
			$billing_fields[ $billing_field_key ] = $fields[ $billing_field_key ];
		}

		return $billing_fields;
	}

	/**
	 * Modify shipping fields priority.
	 *
	 * @since 1.0.0
	 * @param array  $fields Address fields used by default.
	 * @param string $country Selected country.
	 */
	public function shipping_fields_priority( $fields, $country ) {
		if ( 'ID' !== $country ) {
			return $fields;
		}

		$need_sort = false;

		if ( isset( $fields['shipping_state'] ) ) {
			$fields['shipping_state']['priority'] = 41;

			$need_sort = true;
		}

		if ( isset( $fields['shipping_city'] ) ) {
			$fields['shipping_city']['priority'] = 42;

			$need_sort = true;
		}

		if ( ! $need_sort ) {
			return $fields;
		}

		$priority_offset = count( $fields ) * 10;
		$shipping_fields = array();

		foreach ( $fields as $key => $value ) {
			$shipping_fields[ $key ] = isset( $value['priority'] ) ? $value['priority'] : $priority_offset;
			$priority_offset        += 10;
		}

		// Sort fields by priority.
		asort( $shipping_fields );

		$shipping_field_keys = array_keys( $shipping_fields );

		foreach ( $shipping_field_keys as $shipping_field_key ) {
			$shipping_fields[ $shipping_field_key ] = $fields[ $shipping_field_key ];
		}

		return $shipping_fields;
	}

	/**
	 * Inject cart packages to calculate shipping for address 2 field.
	 *
	 * @since 1.1.4
	 * @param array $packages Current cart contents packages.
	 * @return array
	 */
	public function inject_cart_shipping_packages( $packages ) {
		$nonce_action    = 'woocommerce-shipping-calculator';
		$nonce_name      = 'woocommerce-shipping-calculator-nonce';
		$address_2_field = 'calc_shipping_address_2';

		if ( isset( $_POST[ $nonce_name ], $_POST[ $address_2_field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
			$address_2 = sanitize_text_field( wp_unslash( $_POST[ $address_2_field ] ) );

			if ( empty( $address_2 ) ) {
				return $packages;
			}

			foreach ( array_keys( $packages ) as $key ) {
				WC()->customer->set_billing_address_2( $address_2 );
				WC()->customer->set_shipping_address_2( $address_2 );
				$packages[ $key ]['destination']['address_2'] = $address_2;
			}
		}

		return $packages;
	}

	/**
	 * Print hidden element for the hidden address 2 field value
	 * in shipping calculator form.
	 *
	 * @since 1.2.4
	 * @return void
	 */
	public function after_shipping_calculator() {
		if ( ! woongkir_instances() ) {
			return;
		}

		$enable_address_2 = apply_filters( 'woocommerce_shipping_calculator_enable_address_2', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( ! $enable_address_2 ) {
			return;
		}

		$address_2 = WC()->cart->get_customer()->get_shipping_address_2();
		?>
		<input type="hidden" id="woongkir_calc_shipping_address_2" value="<?php echo esc_attr( $address_2 ); ?>" />
		<?php
	}

	/**
	 * Register shipping method to WooCommerce.
	 *
	 * @since 1.0.0
	 *
	 * @param array $methods Registered shipping methods.
	 */
	public function register_shipping_method( $methods ) {
		if ( class_exists( 'Woongkir_Shipping_Method' ) ) {
			$methods[ WOONGKIR_METHOD_ID ] = 'Woongkir_Shipping_Method';
		}

		return $methods;
	}
}
