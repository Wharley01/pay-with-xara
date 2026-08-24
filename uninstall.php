<?php
/**
 * Uninstall Pay with Xara.
 *
 * @package PayWithXara
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_xara_settings' );

if ( function_exists( 'delete_site_option' ) ) {
	delete_site_option( 'woocommerce_xara_settings' );
}
