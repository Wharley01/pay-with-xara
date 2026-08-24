<?php
/**
 * Plugin bootstrap.
 *
 * @package PayWithXara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the gateway, webhook, and Checkout Blocks integration.
 */
final class Xara_WC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Xara_WC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return Xara_WC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		$this->includes();

		load_plugin_textdomain( 'pay-with-xara', false, dirname( XARA_WC_PLUGIN_BASENAME ) . '/languages' );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . XARA_WC_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
		add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );

		if ( defined( 'XARA_WC_GITHUB_REPO' ) && XARA_WC_GITHUB_REPO ) {
			new Xara_WC_Updater( XARA_WC_PLUGIN_FILE, XARA_WC_GITHUB_REPO, XARA_WC_VERSION );
		}
	}

	private function includes() {
		require_once XARA_WC_PLUGIN_DIR . 'includes/class-api-client.php';
		require_once XARA_WC_PLUGIN_DIR . 'includes/class-gateway.php';
		require_once XARA_WC_PLUGIN_DIR . 'includes/class-webhook-controller.php';
		require_once XARA_WC_PLUGIN_DIR . 'includes/class-updater.php';
	}

	/**
	 * @param array $gateways
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'Xara_WC_Gateway';
		return $gateways;
	}

	/**
	 * @param array $links
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xara' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'pay-with-xara' ) . '</a>'
		);

		return $links;
	}

	public function register_blocks_support() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once XARA_WC_PLUGIN_DIR . 'includes/class-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $payment_method_registry ) {
				$payment_method_registry->register( new Xara_WC_Blocks_Support() );
			}
		);
	}

	public function register_webhook_route() {
		$controller = new Xara_WC_Webhook_Controller();
		$controller->register_routes();
	}
}
