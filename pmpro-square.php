<?php
/*
Plugin Name: Paid Memberships Pro - Square Gateway
Plugin URI: https://www.paidmembershipspro.com/add-ons/square
Description: PMPro Gateway integration for Square
Version: 0.1
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com
Text Domain: pmpro-square
Domain Path: /languages
*/

define( "PMPRO_SQUARE_DIR", dirname( __FILE__ ) );

/**
 * Loads rest of Square gateway if PMPro is active.
 */
function pmpro_square_load_gateway() {

	if ( class_exists( 'PMProGateway' ) ) {
		require_once( 'vendor/autoload.php' );
		require_once( PMPRO_SQUARE_DIR . '/classes/class.pmprogateway_square.php' );
		add_action( 'wp_ajax_nopriv_square-webhook', 'pmpro_wp_ajax_square_webhook' );
		add_action( 'wp_ajax_square-webhook', 'pmpro_wp_ajax_square_webhook' );
	}

}
add_action( 'plugins_loaded', 'pmpro_square_load_gateway' );

/**
 * Callback for Square Webhook
 */
function pmpro_wp_ajax_square_webhook() {

	require_once( dirname(__FILE__) . "/webhook.php" );
	exit;
}
add_action( 'wp_ajax_nopriv_square-webhook', 'pmpro_wp_ajax_square_webhook' );
add_action( 'wp_ajax_square-webhook', 'pmpro_wp_ajax_square_webhook' );

/**
 * Runs only when the plugin is activated.
 *
 * @since 0.1.0
 */
function pmpro_square_admin_notice_activation_hook() {
	// Create transient data.
	set_transient( 'pmpro-square-admin-notice', true, 5 );
}
register_activation_hook( __FILE__, 'pmpro_square_admin_notice_activation_hook' );

/**
 * Admin Notice on Activation.
 *
 * @since 0.1.0
 */
function pmpro_square_admin_notice() {
	// Check transient, if available display notice.
	if ( get_transient( 'pmpro-square-admin-notice' ) ) { 
	?>
		<div class="updated notice is-dismissible">
			<p><?php printf( 
				esc_html__( 'Thank you for activating the Paid Memberships Pro: Square Add On. %s to configure the Square Payment Gateway.', 'pmpro-square' ), 
				'<a href="' . esc_url( get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) ) . '">' . esc_html__( 'Visit the payment settings page', 'pmpro-square' ) . '</a>' 
			); ?></p>
		</div>
		<?php
		// Delete transient, only display this notice once.
		delete_transient( 'pmpro-square-admin-notice' );
	}
}
add_action( 'admin_notices', 'pmpro_square_admin_notice' );

/**
 * Function to add links to the plugin action links
 *
 * @param array $links Array of links to be shown in plugin action links.
 */
function pmpro_square_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$new_links = array(
			'<a href="' . get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) . '">' . __( 'Configure Square', 'pmpro-square' ) . '</a>',
		);
		$links  = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pmpro_square_plugin_action_links' );

/**
 * Function to add links to the plugin row meta
 *
 * @param array  $links Array of links to be shown in plugin meta.
 * @param string $file Filename of the plugin meta is being shown for.
 */
function pmpro_square_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-square.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'https://www.paidmembershipspro.com/add-ons/square/' ) . '" title="' . esc_attr__( 'View Documentation', 'pmpro-square' ) . '">' . esc_html__( 'Docs', 'pmpro-square' ) . '</a>',
			'<a href="' . esc_url( 'https://www.paidmembershipspro.com/support/' ) . '" title="' . esc_attr__( 'Visit Customer Support Forum', 'pmpro-square' ) . '">' . esc_html__( 'Support', 'pmpro-square' ) . '</a>',
		);
		$links = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmpro_square_plugin_row_meta', 10, 2 );

/**
 * Load the languages folder for translations.
 */
function pmprosquare_load_textdomain(){
	load_plugin_textdomain( 'pmpro-square' );
}
add_action( 'plugins_loaded', 'pmprosquare_load_textdomain' );

if ( ! function_exists( '_log' ) ) {
	function _log( $message, $pre = '' ) {
		if ( true === WP_DEBUG ) {
			if ( $pre ) {
				error_log( $pre );
			}
			if ( is_array( $message ) || is_object( $message ) ){
				error_log( print_r( $message, true ) );
			} else {
				error_log( $message );
			}
		}
	}
}


