<?php
/**
 * Plugin Name: Pay with Xara
 * Plugin URI: https://usexara.ai
 * Description: Accept payments on WooCommerce via Xara. Customers receive an invoice on WhatsApp and pay with Xara wallet, bank transfer, and more.
 * Version: 1.0.2
 * Author: Xara
 * Author URI: https://usexara.ai
 * Text Domain: pay-with-xara
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * WC tested up to: 11.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PayWithXara
 */

defined( 'ABSPATH' ) || exit;

define( 'XARA_WC_VERSION', '1.0.2' );
define( 'XARA_WC_GITHUB_REPO', 'Wharley01/wp-pay-with-xara' );
define( 'XARA_WC_PLUGIN_FILE', __FILE__ );
define( 'XARA_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'XARA_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'XARA_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', XARA_WC_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', XARA_WC_PLUGIN_FILE, true );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Pay with Xara requires WooCommerce to be installed and active.', 'pay-with-xara' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once XARA_WC_PLUGIN_DIR . 'includes/class-plugin.php';
		Xara_WC_Plugin::instance()->init();
	}
);
