<?php
/**
 * Xara Graph REST client.
 *
 * @package PayWithXara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-to-server client for the Xara Business API.
 */
class Xara_WC_API_Client {

	const DEFAULT_BASE_URL = 'https://graph.usexara.ai/rest';

	/**
	 * @var string
	 */
	private $base_url;

	/**
	 * @var string
	 */
	private $api_token;

	/**
	 * @var bool
	 */
	private $debug;

	/**
	 * @param string $api_token
	 * @param string $base_url
	 * @param bool   $debug
	 */
	public function __construct( $api_token, $base_url = '', $debug = false ) {
		$this->api_token = self::normalize_token( $api_token );
		$this->base_url  = untrailingslashit( $base_url ? $base_url : self::DEFAULT_BASE_URL );
		$this->debug     = (bool) $debug;
	}

	/**
	 * @param string $token
	 * @return string
	 */
	public static function normalize_token( $token ) {
		$token = trim( (string) $token );
		$token = trim( $token, "\"'" );
		$token = preg_replace( '/\s+/', '', $token );
		$token = preg_replace( '/^Bearer/i', '', $token );
		return is_string( $token ) ? trim( $token, " \t\n\r\0\x0B\"'" ) : '';
	}

	/**
	 * Create or reuse an integration invoice.
	 *
	 * @param array $payload
	 * @return array{success:bool,data?:array,message?:string,status_code?:int}
	 */
	public function create_invoice( array $payload ) {
		$items = array();
		foreach ( $payload['items'] ?? array() as $item ) {
			$items[] = array(
				'description' => $item['name'] ?? $item['description'] ?? 'Item',
				'quantity'    => (int) ( $item['quantity'] ?? 1 ),
				'amount'      => (float) ( $item['unit_price'] ?? $item['amount'] ?? 0 ),
			);
		}

		$body = array(
			'invoice_type'        => 'sale',
			'customerPhoneNumber' => $payload['phone'] ?? '',
			'customerName'        => $payload['customer_name'] ?? '',
			'note'                => $payload['note'] ?? '',
			'includeVat'          => false,
			'items'               => $items,
		);

		if ( ! empty( $payload['delivery_address'] ) && is_array( $payload['delivery_address'] ) ) {
			$body['delivery_address'] = $payload['delivery_address'];
		}

		return $this->request(
			'POST',
			'Payment/initiateInvoice',
			$body
		);
	}

	/**
	 * @param string $reference
	 * @return array{success:bool,data?:array,message?:string,status_code?:int}
	 */
	public function get_invoice( $reference ) {
		return $this->request(
			'GET',
			'Payment/getInvoiceDetails',
			array(),
			array( 'reference' => $reference )
		);
	}

	/**
	 * @return array{success:bool,data?:array,message?:string,status_code?:int}
	 */
	public function get_webhooks() {
		return $this->request( 'GET', 'WebhookUrl/getAll' );
	}

	/**
	 * @param string $url
	 * @param string $secret
	 * @return array{success:bool,data?:array,message?:string,status_code?:int}
	 */
	public function create_webhook( $url, $secret ) {
		return $this->request(
			'POST',
			'WebhookUrl/create',
			array(
				'url'    => $url,
				'secret' => $secret,
				'events' => array( 'invoice.paid', 'invoice.payment_received' ),
			)
		);
	}

	/**
	 * Register the store webhook if it is not already present.
	 *
	 * @param string $url
	 * @param string $secret
	 * @return array{success:bool,created:bool,message?:string}
	 */
	public function ensure_webhook( $url, $secret ) {
		$existing = $this->get_webhooks();
		if ( $existing['success'] && ! empty( $existing['data'] ) && is_array( $existing['data'] ) ) {
			foreach ( $existing['data'] as $webhook ) {
				if ( isset( $webhook['url'] ) && untrailingslashit( $webhook['url'] ) === untrailingslashit( $url ) ) {
					return array(
						'success' => true,
						'created' => false,
					);
				}
			}
		}

		$result = $this->create_webhook( $url, $secret );
		return array(
			'success' => ! empty( $result['success'] ),
			'created' => ! empty( $result['success'] ),
			'message' => $result['message'] ?? '',
		);
	}

	/**
	 * @param string $method
	 * @param string $service
	 * @param array  $body
	 * @param array  $query
	 * @return array{success:bool,data?:array,message?:string,status_code?:int}
	 */
	private function request( $method, $service, array $body = array(), array $query = array() ) {
		$url = $this->base_url . '/' . ltrim( $service, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'PayWithXara-WooCommerce/' . XARA_WC_VERSION,
		);

		if ( $this->api_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_token;
			$headers['X-Auth-Token']  = $this->api_token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
		);

		if ( 'GET' !== $method ) {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( $body );
		}

		$this->log( sprintf( 'Request %s %s', $method, $url ) );

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->log( 'Request failed: ' . $response->get_error_message() );
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			$this->log( sprintf( 'Invalid JSON response (%d): %s', $status_code, $raw_body ) );
			return array(
				'success'     => false,
				'status_code' => $status_code,
				'message'     => $this->unexpected_response_message( $status_code, $raw_body ),
			);
		}

		$data    = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;
		$message = isset( $decoded['msg'] ) ? (string) $decoded['msg'] : '';
		if ( isset( $decoded['message'] ) ) {
			$message = (string) $decoded['message'];
		}

		if ( isset( $decoded['status_code'] ) ) {
			$status_code = (int) $decoded['status_code'];
		}

		$success = $status_code >= 200 && $status_code < 300;
		if ( $message && preg_match( '/not logged in/i', $message ) ) {
			$success = false;
			$message = __( 'Xara rejected the API token. Generate a new token on the Developers page and paste it here without the word Bearer.', 'pay-with-xara' );
		}
		$this->log(
			sprintf(
				'Response %d %s',
				$status_code,
				$success ? 'ok' : $message
			)
		);

		return array(
			'success'     => $success,
			'status_code' => $status_code,
			'data'        => $data,
			'message'     => $message,
		);
	}

	/**
	 * @param int    $status_code
	 * @param string $raw_body
	 * @return string
	 */
	private function unexpected_response_message( $status_code, $raw_body ) {
		if ( false !== stripos( $raw_body, '400 Bad Request' ) ) {
			return __( 'Xara rejected the request (HTTP 400). Paste a freshly generated API token and save again.', 'pay-with-xara' );
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Unexpected response from Xara (HTTP %d).', 'pay-with-xara' ),
			$status_code
		);
	}

	/**
	 * @param string $message
	 */
	private function log( $message ) {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->debug( $message, array( 'source' => 'xara' ) );
	}
}
