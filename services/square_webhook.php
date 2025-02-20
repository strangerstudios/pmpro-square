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

pmpro_doing_webhook( 'square', true );

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

// Invoice.payment_made is when information on the subscription is available and also has the payment_transaction_id
if ( $webhook['type'] === 'invoice.payment_made' ) {

	pmpro_square_webhook_log( 'Webhook received: invoice.payment_made' );

	$invoice = $webhook['data']['object']['invoice'];
	$order_id = $invoice['order_id'];
	$subscription_id = $invoice['subscription_id'];

	// We have to look up by subscription as on initial payment the API does not yet give us a payment ID.
	$order = new MemberOrder();
	$order->getLastMemberOrderBySubscriptionTransactionID( $subscription_id );

	//no? create it
	if ( empty( $order->id ) ) {				
		
		$user_id = $order->user_id;
		$user = get_userdata( $user_id );

		if ( empty( $user ) ) {
			pmpro_square_webhook_log( "Couldn't find the old order's user. Order ID = " . $order->id . "." );
			pmpro_square_webhook_exit();
		}

		//alright. create a new order.
		$morder = new MemberOrder();
		$morder->user_id = $order->user_id;
		$morder->membership_id = $order->membership_id;
		$morder->timestamp = strtotime( $invoice['created_at'] );
		
		global $pmpro_currency;
		global $pmpro_currencies;

		// Get the order from Square as that has all the actual payment details.
		$api_response = $square_gateway->client->getOrdersApi()->retrieveOrder( $order_id );
		if ( $api_response->isSuccess() ) {
			$square_order = $api_response->getResult()->getOrder();
		} else {
			$errors = $api_response->getErrors();
			pmpro_square_webhook_log( "Couldn't find the order in Square = " . $order_id . "." );
			pmpro_square_webhook_exit();
		}
		
		$square_taxes = $square_order->getTaxes();
		if ( ! empty( $square_taxes ) ) { // We have taxes as part of the order.
			pmpro_square_webhook_log( $square_taxes );
			$morder->total = $square_order->getTotalMoney()->getAmount() / 100;
			$morder->tax = $square_order->getTaxMoney()->getAmount() / 100;
			$morder->subtotal = $morder->total - $morder->tax; // Back in the subtotal value.
		} else { // No taxes, more straightforward.
			$morder->subtotal = $square_order->getTotalMoney()->getAmount() / 100;
			$morder->tax = 0;
		}

		$morder->payment_transaction_id = $square_order->getId();
		$morder->subscription_transaction_id = $subscription_id;

		$morder->gateway = $old_order->gateway;
		$morder->gateway_environment = $old_order->gateway_environment;

		// Update payment method and billing address on order.
		$customer_id = $square_order->getCustomerId();
		pmpro_square_webhook_log( 'Customer ID: ' . $customer_id );
		$customer = null;
		if ( ! empty( $customer_id ) ) {
			$api_response = $square_gateway->client->getCustomersApi()->retrieveCustomer( $customer_id );
			if ( $api_response->isSuccess() ) {
				$customer = $api_response->getResult()->getCustomer();
			}
		}

		$morder = pmpro_square_webhook_populate_order_from_payment( $morder, $square_order, $customer );				

		//save
		$morder->status = "success";
		$morder->saveOrder();
		$morder->getMemberOrderByID( $morder->id );

		//email the user their order
		$pmproemail = new PMProEmail();
		$pmproemail->sendInvoiceEmail( $user, $morder );

		pmpro_square_webhook_log( "Created new order with ID #" . $morder->id . " for subscription transaction ID: " . $subscription_id );

		do_action( 'pmpro_subscription_payment_completed', $morder );
	
	} else {

		// Order already exists, update it with the order ID. 
		pmpro_square_webhook_log( "Order already exists, so updating it = " . $order_id . "." );

		$api_response = $square_gateway->client->getOrdersApi()->retrieveOrder( $order_id );
		if ( $api_response->isSuccess() ) {
			$square_order = $api_response->getResult()->getOrder();
		} else {
			$errors = $api_response->getErrors();
			pmpro_square_webhook_log( "Couldn't find the order in Square = " . $order_id . "." );
			pmpro_square_webhook_exit();
		}

		$customer_id = $square_order->getCustomerId();
		pmpro_square_webhook_log( 'Customer ID: ' . $customer_id );
		$customer = null;
		if ( ! empty( $customer_id ) ) {
			$api_response = $square_gateway->client->getCustomersApi()->retrieveCustomer( $customer_id );
			if ( $api_response->isSuccess() ) {
				$customer = $api_response->getResult()->getCustomer();
			}
		}

		$order = pmpro_square_webhook_populate_order_from_payment( $order, $square_order, $customer );				

		$order->saveOrder();
		pmpro_square_webhook_log( 'Order updated with subscription transaction ID: ' . $order_id );

	}

	pmpro_square_webhook_exit();

} elseif ( $webhook['type'] === 'payment.updated' || $webhook['type'] === 'payment.created' ) {

	pmpro_square_webhook_log( 'Webhook received: ' . $webhook['type'] );

	$square_payment = $webhook['data']['object']['payment'];
	$status = $square_payment['status'];

	if ( $status === 'FAILED' ) {

		pmpro_square_webhook_log( 'Payment failed...' );

		$square_order_id = $square_payment['order_id'];

		$order = new MemberOrder();
		$order->getMemberOrderByPaymentTransactionID( $square_order_id );
	
		if ( ! empty( $order->id ) ) {				
	
			do_action( "pmpro_subscription_payment_failed", $order );
	
			$api_response = $square_gateway->client->getOrdersApi()->retrieveOrder( $square_order_id );
			if ( $api_response->isSuccess() ) {
				$square_order = $api_response->getResult()->getOrder();
			} else {
				$errors = $api_response->getErrors();
				pmpro_square_webhook_log( "Couldn't find the order in Square = " . $square_order_id . "." );
				pmpro_square_webhook_exit();
			}

			//prep this order for the failure emails.
			$morder = new MemberOrder();
			$morder->user_id = $user_id;
			$morder->membership_id = $order->membership_id;
			
			$user_id = $order->user_id;
			$user = get_userdata( $user_id );
	
			if ( empty( $user ) ) {
				pmpro_square_webhook_log( "Couldn't find the old order's user. Order ID = " . $order->id . "." );
				pmpro_square_webhook_exit();
			}			
	
			$customer_id = $square_order->getCustomerId();
			pmpro_square_webhook_log( 'Customer ID: ' . $customer_id );
			$customer = null;
			if ( ! empty( $customer_id ) ) {
				$api_response = $square_gateway->client->getCustomersApi()->retrieveCustomer( $customer_id );
				if ( $api_response->isSuccess() ) {
					$customer = $api_response->getResult()->getCustomer();
				}
			}
	
			pmpro_square_webhook_populate_order_from_payment( $morder, $square_order, $customer );				
		
			// Email the user and ask them to update their credit card information.
			$pmproemail = new PMProEmail();
			$pmproemail->sendBillingFailureEmail( $user, $morder );
	
			// Email admin so they are aware of the failure.
			$pmproemail = new PMProEmail();
			$pmproemail->sendBillingFailureAdminEmail( get_bloginfo( "admin_email" ), $morder );
	
			pmpro_square_webhook_log( "Subscription payment failed on order ID #" . $order->id );
		
		} else {
			pmpro_square_webhook_log( "Could not find order with ID " . $square_order_id );
		}
	
	}

	pmpro_square_webhook_exit();


} elseif ( $webhook['type'] === 'subscription.updated' ) {

	pmpro_square_webhook_log( 'Webhook received: subscription.updated' );

	// Looking for a cancellation here.
	$subscription = $webhook['data']['object'];
	pmpro_square_webhook_log( $subscription );

	if ( $subscription['status'] == 'CANCELED' ) {
		$subscription_id = $subscription['id'];
		$environment = ( str_contains( $subscription['source']['application_id'], 'sandbox' ) ) ? 'sandbox' : 'live'; // If "sandbox" is in the app id, we are in sandbox mode for this subscription.
		$result = pmpro_handle_subscription_cancellation_at_gateway( $subscription_id, 'square', $environment );
		pmpro_square_webhook_log( 'Cancelled membership for user with ID = ' . $user->ID . '. Subscription transaction ID = ' . $subscription_id . '.' );
	}

	pmpro_square_webhook_exit();

}

pmpro_square_webhook_log( 'Webhook received, no action taken: ' . $webhook['type'] );
pmpro_unhandled_webhook();
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

function pmpro_square_webhook_populate_order_from_payment( $order, $square_order, $customer ) {
	global $square_gateway;

	$order->payment_transaction_id = $square_order->getId();

	// Get the payments on the order to set the payment transaction ID.
	$tenders = $square_order->getTenders();
	if ( ! empty( $tenders ) ) {
		$tender = $tenders[0];
		//$order->payment_transaction_id = $tender->getPaymentId();

		// Get card details
		$card = $tender->getCardDetails()->getCard();
		$order->payment_type = 'Square ' . $card->getCardType();
		$order->cardtype = $card->getCardBrand();
		$order->accountnumber = hideCardNumber( $card->getLast4() );
		$order->expirationmonth = $card->getExpMonth();
		$order->expirationyear = $card->getExpYear();

		$billing = $card->getBillingAddress();
		if ( ! empty( $billing ) ) {
			$order->billing = new stdClass();
			$order->billing->name = $billing->getFirstName() . ' ' . $billing->getLastName();
			$order->billing->street = $billing->getAddressLine1();
			$order->billing->street2 = $billing->getAddressLine2();
			$order->billing->city = $billing->getLocality();
			$order->billing->state = $billing->getAdministrativeDistrictLevel1();
			$order->billing->zip = $billing->getPostalCode();
			$order->billing->country = $billing->getCountry();
			$order->billing->phone = $billing->getPhoneNumber();
		} elseif ( ! empty( $customer ) ) {
			$address = $customer->getAddress();
			if ( ! empty( $address ) ) {
				$order->billing = new stdClass();
				$order->billing->name = $customer->getGivenName() . ' ' . $customer->getFamilyName();
				$order->billing->street = $address->getAddressLine1();
				$order->billing->street2 = $address->getAddressLine2();
				$order->billing->city = $address->getLocality();
				$order->billing->state = $address->getAdministrativeDistrictLevel1();
				$order->billing->zip = $address->getPostalCode();
				$order->billing->country = $address->getCountry();
				$order->billing->phone = $customer->getPhoneNumber();
			}
		} else {
			$order->find_billing_address();
		}

	}
	
	pmpro_square_webhook_log( $order );
	
	return $order;
}