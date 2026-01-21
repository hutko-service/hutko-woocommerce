<?php
/**
 * Plugin Name: hutko payment
 * Plugin URL: https://hutko.org/uk/tools/integrations/wordpress/woocommerce/
 * Description: hutko Payment Gateway for WooCommerce.
 * Author: hutko
 * Author URI: https://hutko.org
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.6
 * WC tested up to: 9.6
 * Version: 3.5.0
 * Text Domain: oplata-woocommerce-payment-gateway
 * Domain Path: /languages
 * Tested up to: 5.8
 * WC tested up to: 5.6
 * WC requires at least: 3.0
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Make sure WooCommerce is active.
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

define( 'WC_OPLATA_DIR', dirname( __FILE__ ) );
define( 'WC_OPLATA_BASE_FILE', __FILE__ );
define( 'WC_OPLATA_VERSION', '3.5.0' );
define( 'WC_OPLATA_MIN_PHP_VER', '5.6.0' );
define( 'WC_OPLATA_MIN_WC_VER', '3.0' );

add_action( 'plugins_loaded', 'woocommerce_gateway_oplata' );

if ( ! class_exists( 'WC_Oplata' ) ) {
	/**
	 * Main plugin class using singleton pattern.
	 *
	 * @since 3.0.0
	 */
	class WC_Oplata {

		/**
		 * Single instance of the class.
		 *
		 * @var WC_Oplata|null
		 */
		private static $instance = null;

		/**
		 * Gets the singleton instance via lazy initialization.
		 *
		 * @return WC_Oplata
		 */
		public static function getInstance() {
			if ( null === static::$instance ) {
				static::$instance = new static();
			}

			return static::$instance;
		}

		/**
		 * Constructor. Initializes the plugin.
		 */
		private function __construct() {
			if ( ! $this->isAcceptableEnv() ) {
				return;
			}

			require_once dirname( __FILE__ ) . '/includes/class-wc-oplata-api.php';
			require_once dirname( __FILE__ ) . '/includes/integration-types/Oplata_Embedded.php';
			require_once dirname( __FILE__ ) . '/includes/integration-types/Oplata_Hosted.php';
			require_once dirname( __FILE__ ) . '/includes/abstract-wc-oplata-payment-gateway.php';
			require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-oplata-card.php';
			require_once dirname( __FILE__ ) . '/includes/compat/class-wc-oplata-pre-orders-compat.php';
			require_once dirname( __FILE__ ) . '/includes/compat/class-wc-oplata-subscriptions-compat.php';

			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
			load_plugin_textdomain( 'oplata-woocommerce-payment-gateway', false, basename( WC_OPLATA_DIR ) . '/languages/' );

			add_action( 'before_woocommerce_init', array( $this, 'declare_cartcheckout_blocks_compatibility' ) );
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_order_approval_payment_method_type' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

			$this->updateSettings();
		}

		/**
		 * Declares compatibility with WooCommerce Cart/Checkout Blocks and HPOS.
		 *
		 * @return void
		 */
		public function declare_cartcheckout_blocks_compatibility() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		}

		/**
		 * Registers the payment method type for WooCommerce Blocks.
		 *
		 * @return void
		 */
		public function register_order_approval_payment_method_type() {
			if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
				return;
			}

			require_once plugin_dir_path( __FILE__ ) . '/includes/class-wc-oplata-block.php';

			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new Oplata_Gateway_Blocks() );
				}
			);
		}

		/**
		 * Adds payment gateway to WooCommerce.
		 *
		 * @param array $gateways Existing gateways.
		 * @return array
		 */
		public function add_gateways( $gateways ) {
			$gateways[] = 'WC_Gateway_Oplata_Card';
			return $gateways;
		}

		/**
		 * Adds settings link to plugins list.
		 *
		 * @param array $links Existing links.
		 * @return array
		 */
		public function plugin_action_links( $links ) {
			$plugin_links = array(
				sprintf(
					'<a href="%1$s">%2$s</a>',
					admin_url( 'admin.php?page=wc-settings&tab=checkout&section=hutko' ),
					__( 'Settings', 'oplata-woocommerce-payment-gateway' )
				),
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Migrates settings from older plugin versions.
		 *
		 * @return void
		 */
		public function updateSettings() {
			if ( version_compare( get_option( 'hutko_woocommerce_version' ), WC_OPLATA_VERSION, '<' ) ) {
				update_option( 'hutko_woocommerce_version', WC_OPLATA_VERSION );
				$settings = maybe_unserialize( get_option( 'woocommerce_oplata_settings', array() ) );

				if ( isset( $settings['salt'] ) ) {
					$settings['secret_key'] = $settings['salt'];
					unset( $settings['salt'] );
				}

				if ( isset( $settings['default_order_status'] ) ) {
					$settings['completed_order_status'] = $settings['default_order_status'];
					unset( $settings['default_order_status'] );
				}

				if ( isset( $settings['payment_type'] ) ) {
					$settings['integration_type'] = 'page_mode' === $settings['payment_type'] ? 'embedded' : 'hosted';
					unset( $settings['payment_type'] );
				}

				unset( $settings['calendar'], $settings['page_mode_instant'], $settings['on_checkout_page'], $settings['force_lang'] );

				update_option( 'woocommerce_oplata_settings', $settings );
			}
		}

		/**
		 * Checks if the environment meets requirements.
		 *
		 * @return bool
		 */
		public function isAcceptableEnv() {
			if ( version_compare( WC_VERSION, WC_OPLATA_MIN_WC_VER, '<' ) ) {
				add_action( 'admin_notices', array( $this, 'woocommerce_oplata_wc_not_supported_notice' ) );
				return false;
			}

			if ( version_compare( phpversion(), WC_OPLATA_MIN_PHP_VER, '<' ) ) {
				add_action( 'admin_notices', array( $this, 'woocommerce_oplata_php_not_supported_notice' ) );
				return false;
			}

			return true;
		}

		/**
		 * Displays WooCommerce version notice.
		 *
		 * @return void
		 */
		public function woocommerce_oplata_wc_not_supported_notice() {
			/* translators: 1) required WC version 2) current WC version */
			$message = sprintf( __( 'Payment Gateway hutko requires WooCommerce %1$s or greater to be installed and active. WooCommerce %2$s is no longer supported.', 'oplata-woocommerce-payment-gateway' ), WC_OPLATA_MIN_WC_VER, WC_VERSION );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * Displays PHP version notice.
		 *
		 * @return void
		 */
		public function woocommerce_oplata_php_not_supported_notice() {
			/* translators: 1) required PHP version 2) current PHP version */
			$message = sprintf( __( 'The minimum PHP version required for hutko Payment Gateway is %1$s. You are running %2$s.', 'oplata-woocommerce-payment-gateway' ), WC_OPLATA_MIN_PHP_VER, phpversion() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * Prevents unserialization of the singleton.
		 *
		 * @throws Exception When trying to unserialize.
		 */
		public function __wakeup() {
			throw new Exception( 'Cannot unserialize singleton' );
		}
	}
}

/**
 * Returns the main instance of WC_Oplata.
 *
 * @return WC_Oplata
 */
function woocommerce_gateway_oplata() {
	return WC_Oplata::getInstance();
}
