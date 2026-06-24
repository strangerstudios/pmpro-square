<?php
/*
 * Plugin Name: Paid Memberships Pro - Square Gateway
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/square
 * Description: PMPro Gateway integration for Square
 * Version: 0.1
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-square
 * License: GPLv3 or later
 * Domain Path: /languages
 */

define( "PMPRO_SQUARE_DIR", plugin_dir_path( __FILE__ ) );
define( "PMPRO_SQUARE_URL", plugin_dir_url( __FILE__ ) );
define( "PMPRO_SQUARE_VERSION", "0.1" );

/**
 * Loads rest of Square gateway if PMPro is active.
 */
function pmpro_square_load_gateway() {

	if ( class_exists( 'PMProGateway' ) ) {
		require_once( PMPRO_SQUARE_DIR . 'classes/class.pmprogateway_square.php' );

		// Admin warnings for recurring levels/discount codes (Square is one-time only).
		if ( is_admin() ) {
			require_once( PMPRO_SQUARE_DIR . 'includes/admin-warnings.php' );
		}
	}

}
add_action( 'plugins_loaded', 'pmpro_square_load_gateway' );

/**
 * Load the plugin's text domain for translations.
 */
function pmpro_square_load_textdomain() {
	load_plugin_textdomain( 'pmpro-square', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'pmpro_square_load_textdomain' );


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
			<p><?php echo wp_kses_post( sprintf( __( 'Thank you for activating the Paid Memberships Pro: Square Add On. <a href="%s">Visit the payment settings page</a> to configure the Square Payment Gateway.', 'pmpro-square' ), esc_url( get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) ) ) ); ?></p>
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
