<?php
/**
 * Signed webhook receiver.
 *
 * @package PayWithXara
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for Xara invoice webhooks.
 */
class Xara_WC_Webhook_Controller {

	const NAMESPACE = 'xara/v1';
	const ROUTE     = '/webhook';

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return string
	 */
	public static function get_url() {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			return new WP_REST_Response( array( 'message' => 'Gateway unavailable' ), 503 );
		}

		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'x-xara-signature' );
		$event     = $request->get_header( 'x-xara-event' );
		$delivery  = $request->get_header( 'x-xara-delivery-id' );

		if ( ! $gateway->verify_webhook_signature( $raw_body, $signature ) ) {
			$gateway->log( 'Rejected webhook with invalid signature.' );
			return new WP_REST_Response( array( 'message' => 'Invalid signature' ), 401 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid payload' ), 400 );
		}

		$event = $event ? $event : ( isset( $payload['event'] ) ? $payload['event'] : '' );
		$gateway->log( sprintf( 'Webhook %s delivery %s', $event, $delivery ) );

		$reference = '';
		if ( ! empty( $payload['payment']['reference'] ) ) {
			$reference = (string) $payload['payment']['reference'];
		} elseif ( ! empty( $payload['invoice']['reference'] ) ) {
			$reference = (string) $payload['invoice']['reference'];
		}

		if ( ! $reference ) {
			return new WP_REST_Response( array( 'message' => 'Missing invoice reference' ), 400 );
		}

		$order = $this->find_order_by_reference( $reference );
		if ( ! $order ) {
			$gateway->log( 'No WooCommerce order found for reference ' . $reference );
			return new WP_REST_Response( array( 'message' => 'Order not found' ), 200 );
		}

		if ( $delivery && $this->already_processed( $order, $delivery ) ) {
			return new WP_REST_Response( array( 'message' => 'Already processed' ), 200 );
		}

		if ( 'invoice.paid' === $event ) {
			$this->complete_order( $order, $payload, $gateway );
		} elseif ( 'invoice.payment_received' === $event ) {
			$this->record_partial_payment( $order, $payload );
		}

		if ( $delivery ) {
			$this->mark_processed( $order, $delivery );
		}

		return new WP_REST_Response( array( 'message' => 'ok' ), 200 );
	}

	/**
	 * @param string $reference
	 * @return WC_Order|null
	 */
	private function find_order_by_reference( $reference ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => Xara_WC_Gateway::META_REFERENCE,
				'meta_value' => $reference,
			)
		);

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * @param WC_Order $order
	 * @param array    $payload
	 * @param Xara_WC_Gateway $gateway
	 */
	private function complete_order( WC_Order $order, array $payload, Xara_WC_Gateway $gateway ) {
		if ( $order->is_paid() ) {
			return;
		}

		$amount = isset( $payload['payment']['amount'] ) ? (float) $payload['payment']['amount'] : 0.0;
		if ( $amount > 0 && ! $gateway->amounts_match( $order, $amount ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: paid amount, 2: order total */
					__( 'Xara reported payment of %1$s which does not match the order total of %2$s. Order was left on hold.', 'pay-with-xara' ),
					wc_price( $amount, array( 'currency' => $order->get_currency() ) ),
					$order->get_formatted_order_total()
				)
			);
			$order->save();
			return;
		}

		$reference = isset( $payload['payment']['reference'] ) ? (string) $payload['payment']['reference'] : $order->get_meta( Xara_WC_Gateway::META_REFERENCE );
		$order->payment_complete( $reference );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Xara invoice reference */
				__( 'Xara payment confirmed. Invoice %s.', 'pay-with-xara' ),
				$reference
			)
		);
	}

	/**
	 * @param WC_Order $order
	 * @param array    $payload
	 */
	private function record_partial_payment( WC_Order $order, array $payload ) {
		$received  = isset( $payload['amount_received'] ) ? (float) $payload['amount_received'] : 0.0;
		$remaining = isset( $payload['payment']['amount_remaining'] ) ? (float) $payload['payment']['amount_remaining'] : 0.0;

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount received, 2: amount remaining */
				__( 'Partial Xara payment received: %1$s. Remaining: %2$s.', 'pay-with-xara' ),
				wc_price( $received, array( 'currency' => $order->get_currency() ) ),
				wc_price( $remaining, array( 'currency' => $order->get_currency() ) )
			)
		);
		$order->save();
	}

	/**
	 * @param WC_Order $order
	 * @param string   $delivery_id
	 * @return bool
	 */
	private function already_processed( WC_Order $order, $delivery_id ) {
		$processed = $order->get_meta( Xara_WC_Gateway::META_DELIVERIES );
		return is_array( $processed ) && in_array( (string) $delivery_id, $processed, true );
	}

	/**
	 * @param WC_Order $order
	 * @param string   $delivery_id
	 */
	private function mark_processed( WC_Order $order, $delivery_id ) {
		$processed   = $order->get_meta( Xara_WC_Gateway::META_DELIVERIES );
		$processed   = is_array( $processed ) ? $processed : array();
		$processed[] = (string) $delivery_id;
		$order->update_meta_data( Xara_WC_Gateway::META_DELIVERIES, array_values( array_unique( $processed ) ) );
		$order->save();
	}

	/**
	 * @return Xara_WC_Gateway|null
	 */
	private function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['xara'] ) && $gateways['xara'] instanceof Xara_WC_Gateway
			? $gateways['xara']
			: null;
	}
}
