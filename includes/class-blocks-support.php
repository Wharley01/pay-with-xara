<?php
/**
 * Checkout Blocks payment method.
 *
 * @package PayWithXara
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Pay with Xara on the WooCommerce Checkout Block.
 */
final class Xara_WC_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * @var string
	 */
	protected $name = 'xara';

	/**
	 * @var Xara_WC_Gateway|null
	 */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_xara_settings', array() );
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$this->gateway  = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway instanceof Xara_WC_Gateway && $this->gateway->is_available();
	}

	/**
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'xara-wc-blocks',
			XARA_WC_PLUGIN_URL . 'assets/js/blocks.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			XARA_WC_VERSION,
			true
		);

		wp_set_script_translations( 'xara-wc-blocks', 'pay-with-xara', XARA_WC_PLUGIN_DIR . 'languages' );

		return array( 'xara-wc-blocks' );
	}

	/**
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Pay with Xara', 'pay-with-xara' ) ),
			'description' => wp_kses_post( $this->get_setting( 'description', '' ) ),
			'icon'        => XARA_WC_PLUGIN_URL . 'assets/images/icon.svg',
			'supports'    => $this->gateway instanceof Xara_WC_Gateway ? $this->gateway->supports : array( 'products' ),
		);
	}
}
