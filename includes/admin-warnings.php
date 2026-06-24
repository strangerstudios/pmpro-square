<?php
/**
 * Admin warnings for Square's one-time-payment-only limitation.
 *
 * Square (as integrated here) only supports one-time payments. Any membership
 * level or discount code configured with recurring billing cannot be honored,
 * and its checkout will be blocked. This file renders inline warnings on the
 * Edit Membership Level and Edit Discount Code screens, plus a top-of-page
 * notice on the relevant PMPro admin screens, whenever Square is the active
 * gateway and a recurring configuration is found. These warnings never block
 * saving; they simply inform the admin.
 *
 * @package PMPro_Square
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Square is the configured primary gateway for the site.
 *
 * Reads the saved option directly (rather than pmpro_getGateway(), which can be
 * overridden by a request param) so admin warnings reflect the real configuration.
 *
 * @return bool
 */
function pmpro_square_is_primary_gateway() {
	return get_option( 'pmpro_gateway' ) === 'square';
}

/**
 * Whether a level-shaped object is configured with recurring billing.
 *
 * Accepts either a membership level object or a per-level discount-code object;
 * both expose the billing_amount / cycle_number / trial_amount fields that
 * pmpro_isLevelRecurring() inspects.
 *
 * @param object $obj The level or per-level discount-code object.
 * @return bool
 */
function pmpro_square_object_is_recurring( $obj ) {
	if ( empty( $obj ) || ! is_object( $obj ) ) {
		return false;
	}
	return pmpro_isLevelRecurring( $obj );
}

/**
 * Whether any membership level is configured with recurring billing.
 *
 * @return bool
 */
function pmpro_square_has_recurring_level() {
	$levels = pmpro_getAllLevels( true );
	if ( empty( $levels ) ) {
		return false;
	}
	foreach ( $levels as $level ) {
		if ( pmpro_square_object_is_recurring( $level ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether any discount code carries recurring billing terms for a level.
 *
 * Discount codes store their own billing terms per level in the
 * pmpro_discount_codes_levels table, so we check those rows directly.
 *
 * @return bool
 */
function pmpro_square_has_recurring_discount_code() {
	global $wpdb;

	$rows = $wpdb->get_results( "SELECT billing_amount, cycle_number, cycle_period, trial_amount FROM $wpdb->pmpro_discount_codes_levels" );
	if ( empty( $rows ) ) {
		return false;
	}
	foreach ( $rows as $row ) {
		if ( pmpro_square_object_is_recurring( $row ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Show a top-of-page admin notice when Square is the active gateway and any
 * level or discount code is configured with recurring billing.
 *
 * Hooked to admin_notices.
 *
 * @return void
 */
function pmpro_square_admin_recurring_notice() {
	// Only when Square is the configured primary gateway.
	if ( ! pmpro_square_is_primary_gateway() ) {
		return;
	}

	// Only to users who can manage the relevant settings.
	if ( ! current_user_can( 'pmpro_membershiplevels' ) && ! current_user_can( 'pmpro_discountcodes' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only on the PMPro admin screens where this is actionable.
	$page = isset( $_REQUEST['page'] ) ? sanitize_text_field( $_REQUEST['page'] ) : '';
	if ( ! in_array( $page, array( 'pmpro-membershiplevels', 'pmpro-discountcodes', 'pmpro-paymentsettings' ), true ) ) {
		return;
	}

	$has_recurring_level    = pmpro_square_has_recurring_level();
	$has_recurring_discount = pmpro_square_has_recurring_discount_code();

	if ( ! $has_recurring_level && ! $has_recurring_discount ) {
		return;
	}

	$messages = array();
	if ( $has_recurring_level ) {
		$messages[] = esc_html__( 'One or more of your membership levels is configured with recurring billing.', 'pmpro-square' );
	}
	if ( $has_recurring_discount ) {
		$messages[] = esc_html__( 'One or more of your discount codes is configured with recurring billing.', 'pmpro-square' );
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Paid Memberships Pro - Square', 'pmpro-square' ); ?>:</strong>
			<?php echo implode( ' ', $messages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Messages are escaped where they are built. ?>
			<?php esc_html_e( 'Square only supports one-time payments, so checkouts for these configurations will be blocked. Set an initial payment only and remove the recurring billing, or use a gateway that supports subscriptions.', 'pmpro-square' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Output an inline warning in the Billing Details section of the Edit Membership
 * Level screen when Square is active and this level is recurring.
 *
 * Hooked to pmpro_membership_level_after_billing_details_settings, which fires
 * with the membership level object.
 *
 * @param object $level The membership level object.
 * @return void
 */
function pmpro_square_admin_level_warning( $level ) {
	if ( ! pmpro_square_is_primary_gateway() ) {
		return;
	}

	// A brand new level (no ID yet) has no saved billing terms to warn about.
	if ( empty( $level ) || empty( $level->id ) ) {
		return;
	}

	if ( ! pmpro_square_object_is_recurring( $level ) ) {
		return;
	}
	?>
	<div class="notice notice-error inline">
		<p><?php esc_html_e( 'Square only supports one-time payments. The recurring billing settings for this level are not supported and checkouts for this level will be blocked. Set an initial payment only.', 'pmpro-square' ); ?></p>
	</div>
	<?php
}

/**
 * Output an inline warning on the Edit Discount Code screen when Square is active
 * and the code carries recurring billing terms for a level.
 *
 * Hooked to pmpro_discount_code_after_level_settings, which fires with the
 * discount code id and the per-level discount-code object.
 *
 * @param int    $edit  The discount code id being edited.
 * @param object $level The per-level discount-code object populated with the code's billing terms.
 * @return void
 */
function pmpro_square_admin_discount_warning( $edit, $level ) {
	if ( ! pmpro_square_is_primary_gateway() ) {
		return;
	}

	// Core sets $level->checked only for levels actually attached to this code. For
	// unattached levels $level is the plain membership level (not the code's billing
	// terms), so skip them to avoid warning about an unrelated level's recurring config.
	if ( empty( $level->checked ) ) {
		return;
	}

	if ( ! pmpro_square_object_is_recurring( $level ) ) {
		return;
	}
	?>
	<div class="notice notice-error inline">
		<p><?php esc_html_e( 'Square only supports one-time payments. The recurring billing settings for this discount code are not supported and checkouts using it will be blocked. Set an initial payment only.', 'pmpro-square' ); ?></p>
	</div>
	<?php
}

add_action( 'admin_notices', 'pmpro_square_admin_recurring_notice' );
add_action( 'pmpro_membership_level_after_billing_details_settings', 'pmpro_square_admin_level_warning' );
add_action( 'pmpro_discount_code_after_level_settings', 'pmpro_square_admin_discount_warning', 10, 2 );
