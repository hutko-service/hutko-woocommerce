<?php
/**
 * Abstract payment gateway class for hutko.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Abstract class for hutko payment gateway.
 *
 * @since 3.0.0
 */
abstract class WC_Oplata_Payment_Gateway extends WC_Payment_Gateway {

	const ORDER_APPROVED   = 'approved';
	const ORDER_DECLINED   = 'declined';
	const ORDER_EXPIRED    = 'expired';
	const ORDER_PROCESSING = 'processing';
	const ORDER_CREATED    = 'created';
	const ORDER_REVERSED   = 'reversed';
	const ORDER_SEPARATOR  = '_';

	const META_NAME_HUTKO_ORDER_ID = '_hutko_order_id';

	/**
	 * Test mode flag.
	 *
	 * @var bool
	 */
	public $test_mode;

	/**
	 * Merchant ID.
	 *
	 * @var string
	 */
	public $merchant_id;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Integration type (embedded or hosted).
	 *
	 * @var string
	 */
	public $integration_type;

	/**
	 * Order status after successful payment.
	 *
	 * @var string
	 */
	public $completed_order_status;

	/**
	 * Order status when payment expires.
	 *
	 * @var string
	 */
	public $expired_order_status;

	/**
	 * Order status when payment is declined.
	 *
	 * @var string
	 */
	public $declined_order_status;

	/**
	 * Custom redirect page ID.
	 *
	 * @var int
	 */
	public $redirect_page_id;

	/**
	 * Whether HPOS is enabled.
	 *
	 * @var bool
	 */
	protected $hpos_in_use;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( $this->test_mode ) {
			$this->merchant_id = WC_Oplata_API::TEST_MERCHANT_ID;
			$this->secret_key  = WC_Oplata_API::TEST_MERCHANT_SECRET_KEY;
		}

		WC_Oplata_API::setMerchantID( $this->merchant_id );
		WC_Oplata_API::setSecretKey( $this->secret_key );

		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'callbackHandler' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		if ( 'embedded' === $this->integration_type ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'includeEmbeddedAssets' ) );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		}

		$this->hpos_in_use = OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Process payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order          = wc_get_order( $order_id );
		$process_result = array(
			'result'   => 'success',
			'redirect' => '',
		);

		try {
			if ( 'embedded' === $this->integration_type ) {
				$process_result['redirect'] = $order->get_checkout_payment_url( true );
			} else {
				$payment_params             = $this->getPaymentParams( $order );
				$process_result['redirect'] = WC_Oplata_API::getCheckoutUrl( $payment_params );
			}
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			$process_result['result'] = 'fail';
		}

		return apply_filters( 'wc_gateway_oplata_process_payment_complete', $process_result, $order );
	}

	/**
	 * Get payment parameters for hutko API.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public function getPaymentParams( $order ) {
		$params = array(
			'order_id'            => $this->createOplataOrderID( $order ),
			'order_desc'          => __( 'Order №: ', 'oplata-woocommerce-payment-gateway' ) . $order->get_id(),
			'amount'              => (int) round( $order->get_total() * 100 ),
			'currency'            => get_woocommerce_currency(),
			'lang'                => $this->getLanguage(),
			'sender_email'        => $this->getEmail( $order ),
			'response_url'        => $this->getResponseUrl( $order ),
			'server_callback_url' => $this->getCallbackUrl(),
			'reservation_data'    => $this->getReservationData( $order ),
		);

		return apply_filters( 'wc_gateway_oplata_payment_params', $params, $order );
	}

	/**
	 * Generate unique hutko order ID and save it to order meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function createOplataOrderID( $order ) {
		$hutko_order_id = $order->get_id() . self::ORDER_SEPARATOR . time();
		$order->update_meta_data( self::META_NAME_HUTKO_ORDER_ID, $hutko_order_id );
		$order->save();

		return $hutko_order_id;
	}

	/**
	 * Get hutko order ID from order meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return mixed
	 */
	public function getOplataOrderID( $order ) {
		return $order->get_meta( self::META_NAME_HUTKO_ORDER_ID );
	}

	/**
	 * Get response URL (thank-you page).
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function getResponseUrl( $order ) {
		return $this->redirect_page_id ? get_permalink( $this->redirect_page_id ) : $this->get_return_url( $order );
	}

	/**
	 * Get transaction URL for merchant portal.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		$this->view_transaction_url = 'https://portal.hutko.org/#/transactions/payments/info/%s/general';
		return parent::get_transaction_url( $order );
	}

	/**
	 * Get checkout token and cache it in session.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 * @throws Exception When API call fails.
	 */
	public function getCheckoutToken( $order ) {
		$order_id          = $order->get_id();
		$amount            = (int) round( $order->get_total() * 100 );
		$currency          = get_woocommerce_currency();
		$session_token_key = 'session_token_' . md5( $this->merchant_id . '_' . $order_id . '_' . $amount . '_' . $currency );
		$checkout_token    = WC()->session->get( $session_token_key );

		if ( empty( $checkout_token ) ) {
			$payment_params = $this->getPaymentParams( $order );
			$checkout_token = WC_Oplata_API::getCheckoutToken( $payment_params );
			WC()->session->set( $session_token_key, $checkout_token );
		}

		return $checkout_token;
	}

	/**
	 * Clear checkout token cache from session.
	 *
	 * @param array $payment_params Payment parameters.
	 * @param int   $order_id       Order ID.
	 * @return void
	 */
	public function clearCache( $payment_params, $order_id ) {
		WC()->session->__unset( 'session_token_' . md5( $this->merchant_id . '_' . $order_id . '_' . $payment_params['amount'] . '_' . $payment_params['currency'] ) );
	}

	/**
	 * Get hutko widget options.
	 *
	 * @return array
	 */
	public function getPaymentOptions() {
		return array(
			'full_screen' => false,
			'email'       => true,
		);
	}

	/**
	 * Get site language code (2 characters).
	 *
	 * @return string
	 */
	public function getLanguage() {
		return substr( get_bloginfo( 'language' ), 0, 2 );
	}

	/**
	 * Get customer email for the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function getEmail( $order ) {
		$current_user = wp_get_current_user();
		$email        = $current_user->user_email;

		if ( empty( $email ) ) {
			$order_data = $order->get_data();
			$email      = $order_data['billing']['email'];
		}

		return $email;
	}

	/**
	 * Get callback URL for payment notifications.
	 *
	 * @return string
	 */
	public function getCallbackUrl() {
		return wc_get_endpoint_url( 'wc-api', strtolower( get_class( $this ) ), get_site_url() );
	}

	/**
	 * Get reservation data for anti-fraud purposes.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Base64-encoded JSON.
	 */
	public function getReservationData( $order ) {
		$order_data         = $order->get_data();
		$order_data_billing = $order_data['billing'];

		$reservation_data = array(
			'customer_zip'       => $order_data_billing['postcode'],
			'customer_name'      => $order_data_billing['first_name'] . ' ' . $order_data_billing['last_name'],
			'customer_address'   => $order_data_billing['address_1'] . ' ' . $order_data_billing['city'],
			'customer_state'     => $order_data_billing['state'],
			'customer_country'   => $order_data_billing['country'],
			'phonemobile'        => $order_data_billing['phone'],
			'account'            => $order_data_billing['email'],
			'cms_name'           => 'Wordpress',
			'cms_version'        => get_bloginfo( 'version' ),
			'cms_plugin_version' => WC_OPLATA_VERSION . ' (Woocommerce ' . WC_VERSION . ')',
			'shop_domain'        => get_site_url(),
			'path'               => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'products'           => $this->getReservationDataProducts( $order->get_items() ),
		);

		return base64_encode( wp_json_encode( $reservation_data ) );
	}

	/**
	 * Get product data for reservation.
	 *
	 * @param array $order_items_products Order item products.
	 * @return array
	 */
	public function getReservationDataProducts( $order_items_products ) {
		$reservation_data_products = array();

		try {
			/** @var WC_Order_Item_Product $order_product */
			foreach ( $order_items_products as $order_product ) {
				$reservation_data_products[] = array(
					'id'           => $order_product->get_product_id(),
					'name'         => $order_product->get_name(),
					'price'        => $order_product->get_product()->get_price(),
					'total_amount' => $order_product->get_total(),
					'quantity'     => $order_product->get_quantity(),
				);
			}
		} catch ( Exception $e ) {
			$reservation_data_products['error'] = $e->getMessage();
		}

		return $reservation_data_products;
	}

	/**
	 * Get available integration types.
	 *
	 * @return array
	 */
	public function getIntegrationTypes() {
		$integration_types = array();

		if ( isset( $this->embedded ) ) {
			$integration_types['embedded'] = __( 'Embedded', 'oplata-woocommerce-payment-gateway' );
		}

		if ( isset( $this->hosted ) ) {
			$integration_types['hosted'] = __( 'Hosted', 'oplata-woocommerce-payment-gateway' );
		}

		return $integration_types;
	}

	/**
	 * Get list of WordPress pages for redirect selection.
	 *
	 * @param string|bool $title  Optional title for first option.
	 * @param bool        $indent Whether to indent child pages.
	 * @return array
	 */
	public function oplata_get_pages( $title = false, $indent = true ) {
		$wp_pages  = get_pages( 'sort_column=menu_order' );
		$page_list = array();

		if ( $title ) {
			$page_list[] = $title;
		}

		foreach ( $wp_pages as $page ) {
			$prefix = '';

			if ( $indent ) {
				$has_parent = $page->post_parent;

				while ( $has_parent ) {
					$prefix    .= ' - ';
					$next_page  = get_post( $has_parent );
					$has_parent = $next_page->post_parent;
				}
			}

			$page_list[ $page->ID ] = $prefix . $page->post_title;
		}

		return $page_list;
	}

	/**
	 * Get available WooCommerce order statuses.
	 *
	 * @return array
	 */
	public function getPaymentOrderStatuses() {
		$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$statuses       = array(
			'default' => __( 'Default status', 'oplata-woocommerce-payment-gateway' ),
		);

		if ( $order_statuses ) {
			foreach ( $order_statuses as $k => $v ) {
				$statuses[ str_replace( 'wc-', '', $k ) ] = $v;
			}
		}

		return $statuses;
	}

	/**
	 * Handle payment callback from hutko.
	 *
	 * @return void
	 */
	public function callbackHandler() {
    try {
        // Read raw input first - prefer JSON body over $_POST
        $rawInput    = file_get_contents( 'php://input' );
        $requestBody = ! empty( $rawInput ) ? json_decode( $rawInput, true ) : null;

        // Fallback to $_POST if JSON decode failed or raw input was empty
        if ( empty( $requestBody ) && ! empty( $_POST ) ) {
            $requestBody = $_POST;
        }

        // Ще один fallback на $_GET якщо потрібно
        if ( empty( $requestBody ) && ! empty( $_GET ) ) {
            $requestBody = $_GET;
        }

        // Перевірка чи є дані взагалі
        if ( empty( $requestBody ) || ! is_array( $requestBody ) ) {
            throw new Exception( 'No valid callback data received' );
        }

        // ДОДАЙ ЦЕ ЛОГУВАННЯ ПЕРЕД БУДЬ-ЯКОЮ ОБРОБКОЮ
        $debug_log = [
            'step' => 'BEFORE_SANITIZE',
            'merchant_id_exists' => isset($requestBody['merchant_id']),
            'merchant_id_value' => $requestBody['merchant_id'] ?? 'NOT_SET',
            'merchant_id_type' => gettype($requestBody['merchant_id'] ?? null),
            'all_keys' => array_keys($requestBody),
        ];
        file_put_contents(
            WP_CONTENT_DIR . '/hutko-debug.log',
            json_encode($debug_log, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 50) . "\n",
            FILE_APPEND
        );

        // Конвертуємо числові значення в рядки (merchant_id, payment_id, card_bin)
        // але НЕ санітизуємо складні поля типу additional_info
        $fieldsToConvert = ['merchant_id', 'payment_id', 'card_bin', 'amount', 'actual_amount'];
        foreach ($fieldsToConvert as $field) {
            if (isset($requestBody[$field]) && is_numeric($requestBody[$field])) {
                $requestBody[$field] = (string) $requestBody[$field];
            }
        }

        // ДОДАЙ ЦЕ ЛОГУВАННЯ ПІСЛЯ КОНВЕРТАЦІЇ
        $debug_log2 = [
            'step' => 'AFTER_CONVERT',
            'merchant_id_exists' => isset($requestBody['merchant_id']),
            'merchant_id_value' => $requestBody['merchant_id'] ?? 'NOT_SET',
            'merchant_id_type' => gettype($requestBody['merchant_id'] ?? null),
        ];
        file_put_contents(
            WP_CONTENT_DIR . '/hutko-debug.log',
            json_encode($debug_log2, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 50) . "\n",
            FILE_APPEND
        );

        // Санітизуємо тільки прості текстові поля, НЕ чіпаємо signature та additional_info
        $fieldsToSanitize = [
            'order_id', 'order_status', 'tran_type', 'currency', 'sender_email',
            'card_type', 'payment_system', 'response_status', 'masked_card',
            'approval_code', 'rrn', 'eci', 'response_code', 'response_description'
        ];
        
        foreach ($fieldsToSanitize as $field) {
            if (isset($requestBody[$field])) {
                $requestBody[$field] = sanitize_text_field($requestBody[$field]);
            }
        }

        // ДОДАЙ ЦЕ ЛОГУВАННЯ ПІСЛЯ САНІТИЗАЦІЇ
        $debug_log3 = [
            'step' => 'AFTER_SANITIZE',
            'merchant_id_exists' => isset($requestBody['merchant_id']),
            'merchant_id_value' => $requestBody['merchant_id'] ?? 'NOT_SET',
            'merchant_id_type' => gettype($requestBody['merchant_id'] ?? null),
        ];
        file_put_contents(
            WP_CONTENT_DIR . '/hutko-debug.log',
            json_encode($debug_log3, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 50) . "\n",
            FILE_APPEND
        );

        // ДОДАЙ ЦЕ ПРЯМО ПЕРЕД validateRequest
        $debug_log4 = [
            'step' => 'BEFORE_VALIDATE',
            'merchant_id_in_array' => $requestBody['merchant_id'] ?? 'MISSING',
            'full_request_body' => $requestBody,
        ];
        file_put_contents(
            WP_CONTENT_DIR . '/hutko-debug.log',
            json_encode($debug_log4, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n",
            FILE_APPEND
        );

        WC_Oplata_API::validateRequest( $requestBody );

        // Ignore reverse callbacks.
        if ( ! empty( $requestBody['reversal_amount'] ) || 'reverse' === $requestBody['tran_type'] ) {
            exit;
        }

        $order_id = strstr( $requestBody['order_id'], self::ORDER_SEPARATOR, true );
        $order    = wc_get_order( $order_id );
        $this->clearCache( $requestBody, $order_id );

        do_action( 'wc_gateway_hutko_receive_valid_callback', $requestBody, $order );

        switch ( $requestBody['order_status'] ) {
            case self::ORDER_APPROVED:
                $this->oplataPaymentComplete( $order, $requestBody['payment_id'] );
                break;

            case self::ORDER_CREATED:
            case self::ORDER_PROCESSING:
                // Default WC pending status is used.
                break;

            case self::ORDER_DECLINED:
                $new_order_status = 'default' !== $this->declined_order_status ? $this->declined_order_status : 'failed';
                /* translators: 1) order status 2) payment ID */
                $order_note = sprintf( __( 'Transaction ERROR: order %1$s<br/>hutko ID: %2$s', 'oplata-woocommerce-payment-gateway' ), $requestBody['order_status'], $requestBody['payment_id'] );
                $order->update_status( $new_order_status, $order_note );
                break;

            case self::ORDER_EXPIRED:
                $new_order_status = 'default' !== $this->expired_order_status ? $this->expired_order_status : 'cancelled';
                /* translators: 1) order status 2) payment ID */
                $order_note = sprintf( __( 'Transaction ERROR: order %1$s<br/>hutko ID: %2$s', 'oplata-woocommerce-payment-gateway' ), $requestBody['order_status'], $requestBody['payment_id'] );
                $order->update_status( $new_order_status, $order_note );
                break;

            default:
                throw new Exception( __( 'Unhandled hutko order status', 'oplata-woocommerce-payment-gateway' ) );
        }
    } catch ( Exception $e ) {
        $log_data = [
            'timestamp'      => date( 'Y-m-d H:i:s' ),
            'error_message'  => $e->getMessage(),
            'error_file'     => $e->getFile(),
            'error_line'     => $e->getLine(),
            'trace'          => $e->getTraceAsString(),
            'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'N/A',
            'request_uri'    => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'N/A',
            'request_body'   => $requestBody ?? null,
            'raw_input'      => $rawInput ?? file_get_contents( 'php://input' ),
            'post_data'      => $_POST,
            'get_data'       => $_GET,
        ];

        $log_file = WP_CONTENT_DIR . '/hutko-callback-errors.log';
        file_put_contents(
            $log_file,
            json_encode( $log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" . str_repeat( '=', 100 ) . "\n\n",
            FILE_APPEND
        );

        error_log( 'Hutko Callback Error: ' . $e->getMessage() . ' | Request: ' . json_encode( $requestBody ?? [] ) );

        if ( ! empty( $order ) ) {
            $order->update_status( 'failed', $e->getMessage() );
        }
        wp_send_json( [ 'error' => $e->getMessage() ], 400 );
    }

    status_header( 200 );
    exit;
}

	/**
	 * Complete payment process.
	 *
	 * @param WC_Order $order          Order object.
	 * @param string   $transaction_id Transaction ID from hutko.
	 * @return void
	 */
	public function oplataPaymentComplete( $order, $transaction_id ) {
		if ( ! $order->is_paid() ) {
			$order->payment_complete( $transaction_id );
			/* translators: %1$s: transaction ID */
			$order_note = sprintf( __( 'hutko payment successful.<br/>hutko ID: %1$s<br/>', 'oplata-woocommerce-payment-gateway' ), $transaction_id );

			if ( 'default' !== $this->completed_order_status ) {
				WC()->cart->empty_cart();
				$order->update_status( $this->completed_order_status, $order_note );
			} else {
				$order->add_order_note( $order_note );
			}
		}
	}
}
