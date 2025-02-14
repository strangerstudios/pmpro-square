<?php

// in case the file is loaded directly
if( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PMPRO_DIR' ) || ! defined( 'PMPRO_SQUARE_DIR' ) ) {
	error_log( __( 'Paid Memberships Pro and the PMPro Square Add On must be activated for the PMPro Square webhook listener to function.', 'pmpro-payfast' ) );
	exit;
}

global $wpdb, $gateway_environment;
$logstr = '';

$square_gateway = new PMProGateway_square();
$square_gateway->setup();

$environment = get_option( 'pmpro_gateway_environment' );
$webhook_data = get_option( 'pmpro_square_webhook_' . $gateway_environment );
if ( empty( $webhook_data ) ) {
	pmpro_square_webhook_log( 'No webhook settings data' );
	pmpro_square_webhook_exit();
}

$headers = apache_request_headers();
$signature = $headers["X-Square-Hmacsha256-Signature"];

// Signature completely empty (Throw a warning/bail)
if ( empty( $signature ) ) {
	pmpro_square_webhook_log( 'No signature found from webhook request' );
	pmpro_square_webhook_exit();
}

$body = '';   
$handle = fopen( 'php://input', 'r' );
while( ! feof( $handle ) ) {
	$body .= fread( $handle, 1024 );
}

if ( ! \Square\Utils\WebhooksHelper::isValidWebhookEventSignature( $body, $signature, $webhook_data['signature_key'], $square_gateway->get_webhook_url() ) ) {
	pmpro_square_webhook_log( "Error verifying webhook signature" );
	pmpro_square_webhook_exit();
}

$webhook = json_decode( $body, true );

// Invoice.payment_made is really the only one we need at the moment.
if ( $webhook['type'] === 'invoice.payment_made' ) {

	pmpro_square_webhook_log( 'Webhook received: invoice.payment_made' );

	$invoice = $webhook['data']['object']['invoice'];

	$sql = $wpdb->prepare( "SELECT id FROM $wpdb->pmpro_membership_orders WHERE subscription_transaction_id = %s", $invoice['subscription_id'] );
	$order_id = $wpdb->get_var( $sql );
	if ( empty( $order_id ) ) {
		pmpro_square_webhook_log( 'Webhook could not find order with square subscription ID: ' . $invoice['subscription_id'] );
		pmpro_square_webhook_exit();
	}

	$order = new MemberOrder( $order_id );

	if ( $invoice['status'] !== 'PAID' ) {
		pmpro_square_webhook_log( 'Order already marked as paid, no action needed for subscription ID: ' . $invoice['subscription_id'] );
		pmpro_square_webhook_exit();
	}

	$order->payment_transaction_id = $invoice['order_id'];
	$order->saveOrder();

	pmpro_square_webhook_log( 'Order updated with subscription transaction ID: ' . $invoice['order_id'] );

	pmpro_pull_checkout_data_from_order( $order );
	pmpro_complete_async_checkout( $order );
	pmpro_square_webhook_exit();

}

pmpro_square_webhook_log( 'Webhook received, no action taken: ' . $webhook['type'] );
pmpro_square_webhook_exit();


function pmpro_square_webhook_log( $s ) {
	global $logstr;
	if ( is_array( $s ) || is_object( $s ) ) {
		$s = print_r( $s, true );
	}
	$logstr .= "\t" . $s . "\n";
}

function pmpro_square_webhook_exit( $status = 200 ) {
	global $logstr, $gateway_environment;

	if ( ! empty( $logstr ) ) {
	
		$file_suffix = substr( md5( get_option( 'pmpro_square_' . $gateway_environment . '_application_id', true ) ), 0, 10 );
		$logfile = PMPRO_SQUARE_DIR . 'logs/square-webhooks-' . $file_suffix . '.txt';
		$logfile = apply_filters( 'pmpro_square_webhook_logfile', $logfile );
	
		if ( ! file_exists( dirname( $logfile ) ) ) {
			mkdir( dirname( $logfile ), 0700 );
		}

		$date = current_time( 'y-m-d H:i:s' );
		$log_message = '[' . $date . '] ' . $logstr;
		$log_message .= "\n";

		$fp = fopen( $logfile, 'a' );
		fwrite( $fp, $log_message );
		fclose( $fp );

		esc_html_e( $log_message );

	}

	http_response_code( 200 );	
	exit;
}
