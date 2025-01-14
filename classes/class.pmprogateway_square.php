<?php
use Square\SquareClientBuilder;
use Square\Authentication\BearerAuthCredentialsBuilder;
use Square\Environment;

//load classes init method
add_action( 'init', array('PMProGateway_Square', 'init' ) );
add_filter( 'pmpro_is_ready', array( 'PMProGateway_Square', 'pmpro_is_square_ready' ), 999, 1 );

class PMProGateway_Square extends PMProGateway {

	// Use this to interact with the Square API via SDK.
	private static $base_url;
	private static $application_id;
	private static $access_token;
	private static $personal_access_token;
	private static $client;
	private static $api_url;

	function __construct( $gateway = NULL ) {

		$this->gateway = $gateway;
		return $this->gateway;
	}

	/**
	 * Run on WP init
	 *
	 * @since 1.8
	 */
	static function init() {

		//make sure Square is a gateway option
		add_filter( 'pmpro_gateways', array( 'PMProGateway_Square', 'pmpro_gateways' ));
		add_filter( 'pmpro_gateways_with_pending_status', array( 'PMProGateway_Square', 'pmpro_gateways_with_pending_status' ) );

		//add fields to payment settings
		add_filter( 'pmpro_payment_options', array( 'PMProGateway_Square', 'pmpro_payment_options' ));
		add_filter( 'pmpro_payment_option_fields', array( 'PMProGateway_Square', 'pmpro_payment_option_fields' ), 10, 2);
		//code to add at checkout
		$gateway = pmpro_getGateway();

		if ( $gateway == "square" ) {			

			add_filter( 'pmpro_include_payment_information_fields', '__return_false');
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_Square', 'pmpro_required_billing_fields' ) );
			add_filter( 'pmpro_checkout_default_submit_button', array( 'PMProGateway_Square', 'pmpro_checkout_default_submit_button' ) );
			// add_filter( 'pmpro_checkout_before_change_membership_level', array( 'PMProGateway_Square', 'pmpro_checkout_before_change_membership_level' ), 10, 2);
			add_action( 'pmpro_after_saved_payment_options', array( 'PMProGateway_Square', 'pmpro_square_create_default_subscription_plan' ) );
			add_action( 'pmpro_after_saved_payment_options', array( 'PMProGateway_Square', 'pmpro_square_refresh_locations_auto' ) );
			add_action( 'pmpro_after_saved_payment_options', array( 'PMProGateway_Square', 'pmpro_square_create_webhooks_auto' ) );
			add_action( 'admin_notices', array( 'PMProGateway_Square', 'pmpro_square_create_default_subscription_plan_manual' ) );
			add_action( 'admin_notices', array( 'PMProGateway_Square', 'pmpro_square_refresh_locations_manual' ) );
			add_action( 'admin_notices', array( 'PMProGateway_Square', 'pmpro_square_create_webhooks_manual' ) );

			add_filter( 'pmpro_include_billing_address_fields', array(
				'PMProGateway_Square',
				'pmpro_include_billing_address_fields'
			) );

			add_action( 'wp', array( 'PMProGateway_Square', 'webhook_listener' ), 999 );

		}
	}

	static function pmpro_gateways_with_pending_status( $gateways ) {

		$gateways[] = 'square';
		return $gateways;

	}


	/**
	 * Make sure this gateway is in the gateways list
	 *
	 * @since 1.8
	 */
	static function pmpro_gateways( $gateways ) {

		if ( empty( $gateways['square'] ) ) {
			$gateways['square'] = __( 'Square', 'pmpro-square' );
		}

		return $gateways;
	}

	/**
	 * Get a list of payment options that the this gateway needs/supports.
	 *
	 * @since 1.8
	 */
	static function getGatewayOptions() {

		$options = array(
			'sslseal',
			'nuclear_HTTPS',
			'gateway_environment',
			'square_sandbox_personal_access_token',
			'square_sandbox_location_id',
			'square_live_personal_access_token',
			'square_live_location_id',
			'square_billingaddress',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate'
		);

		return $options;
	}

	/**
	 * Set payment options for payment settings page.
	 *
	 * @since 1.8
	 */
	static function pmpro_payment_options( $options ) {
		//get square options
		$square_options = PMProGateway_Square::getGatewayOptions();

		//merge with others.
		$options = array_merge( $square_options, $options );

		return $options;
	}

	static function pmpro_square_setup() {

		if ( ! empty( PMProGateway_Square::$client ) ) {
			return; // Don't run again.
		}

		$environment = get_option( 'pmpro_gateway_environment' );
		if ( $environment == 'live' ) {
			PMProGateway_Square::$base_url = 'https://connect.squareup.com';
			//PMProGateway_Square::$application_id = get_option( 'pmpro_square_live_application_id' );
			//PMProGateway_Square::$access_token = get_option( 'pmpro_square_live_access_token' );
			PMProGateway_Square::$personal_access_token = get_option( 'pmpro_square_live_personal_access_token' );
			PMProGateway_Square::$api_url = 'https://connect.squareup.com';
		} else {
			PMProGateway_Square::$base_url = 'https://connect.squareupsandbox.com';
			//PMProGateway_Square::$application_id = get_option( 'pmpro_square_sandbox_application_id' );
			//PMProGateway_Square::$access_token = get_option( 'pmpro_square_sandbox_access_token' );
			PMProGateway_Square::$personal_access_token = get_option( 'pmpro_square_sandbox_personal_access_token' );
			PMProGateway_Square::$api_url = 'https://connect.squareupsandbox.com';
		}
		if ( empty( PMProGateway_Square::$personal_access_token ) ) {
			return; // Don't proceed, we don't have the proper credentials.
		}
		PMProGateway_Square::$client = SquareClientBuilder::init()
		->bearerAuthCredentials(
			BearerAuthCredentialsBuilder::init( PMProGateway_Square::$personal_access_token )
		)
		->environment( ( $environment == 'live' ) ? Environment::LIVE : Environment::SANDBOX )
		->squareVersion( '2024-12-18' )
		->build();
		
	}

	/**
	 * Check if all fields are complete
	 */
	static function pmpro_is_square_ready( $ready = false ){

		$environment = get_option( 'pmpro_gateway_environment' );
		if ( $environment == 'live' ) {
			$access_token = get_option( 'pmpro_square_live_personal_access_token' );
		} else {
			$access_token = get_option( 'pmpro_square_sandbox_personal_access_token' );
		}

		if ( $access_token ) {
			$ready = true;
		} else {
			$ready = false;
		}

		return $ready;

	}

	static function pmpro_square_create_default_subscription_plan_manual() {

		if ( empty( $_GET['pmpro_square_create_default_subscription'] ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_create_default_subscription' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_create_default_subscription', 'GET' );
		$result = PMProGateway_Square::pmpro_square_create_default_subscription_plan( $environment );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Subscriptions are now enabled', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Subscriptions could not be enabled', 'pmpro-square' ); ?>: <?php echo esc_html_e( $result['error'] ); ?></p>
			</div>
			<?php	
		}

	}

	/**
	 * Creates the default subscription plan in Square to then build all variations from
	 */
	static function pmpro_square_create_default_subscription_plan() {

		$subscription_plan = pmpro_getOption( 'square_subscription_plan_id' );
		if ( ! $subscription_plan && PMProGateway_Square::pmpro_is_square_ready() ) {

			PMProGateway_Square::pmpro_square_setup();

			$subscription_plan_data = new \Square\Models\CatalogSubscriptionPlan( 'Paid Memberships Pro' );
			$subscription_plan_data->setAllItems( false );

			$object = new \Square\Models\CatalogObject( 'SUBSCRIPTION_PLAN', '#paid-memberships-pro' );
			$object->setSubscriptionPlanData( $subscription_plan_data );

			$body = new \Square\Models\UpsertCatalogObjectRequest( 'paid-memberships-pro', $object );

			$api_response = PMProGateway_Square::$client->getCatalogApi()->upsertCatalogObject( $body );

			if ( $api_response->isSuccess() ) {
				$catalog_object = $api_response->getResult()->getCatalogObject();
				pmpro_setOption( 'square_subscription_plan_id', $catalog_object->getId() );
				return array( 'success' => true, 'plan' => $catalog_object->getId() );
			} else {
				return array( 'error' => $api_response->getErrors() );
			}
		}

		return array( 'error' => __( 'Unknown error', 'pmpro-square' ) );

	}

	static function pmpro_square_refresh_locations_manual() {

		if ( empty( $_GET['pmpro_square_refresh_locations'] ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_refresh_locations' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_refresh_locations', 'GET' );
		$result = PMProGateway_Square::pmpro_square_refresh_locations( $environment );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Locations have been refreshed', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Locations could not be refreshed', 'pmpro-square' ); ?>: <?php echo esc_html_e( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}

	static function pmpro_square_refresh_locations_auto() {

		$environment = pmpro_getOption( 'gateway_environment' );
		$existing_locations = pmpro_getOption( 'square_locations_' . $environment );

		if ( empty( $existing_locations ) && PMProGateway_Square::pmpro_is_square_ready() ) {
			PMProGateway_Square::pmpro_square_refresh_locations( $environment );
		}

	}

	static function pmpro_square_refresh_locations( $environment ) {

		if ( PMProGateway_Square::pmpro_is_square_ready() ) {
			_log( 'refreshing locations' );
			PMProGateway_Square::pmpro_square_setup();
			$api_response = PMProGateway_Square::$client->getLocationsApi()->listLocations();
			if ( $api_response->isSuccess() ) {
				$api_locations = $api_response->getResult()->getLocations();
				$locations = array();
				foreach ( $api_locations as $location ) {
					$locations[ $location->getId() ] = $location->getName();
				}
				update_option( 'pmpro_square_locations_' . $environment, $locations );
				return array( 'success' => true );
			} else {
				return array( 'error' => $api_response->getErrors() );
			}
		}

		return array( 'error' => __( 'Unknown error', 'pmpro-square' ) );
		
	}

	static function pmpro_square_get_webhook_url() {
		$url = trailingslashit( get_bloginfo( 'url' ) );
		$url = add_query_arg( 'pmpro_square_webhook', 1, $url );
		return $url;
	}

	static function pmpro_square_create_webhooks_manual() {

		if ( empty( $_GET['pmpro_square_webhooks'] ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_webhooks' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_webhooks', 'GET' );
		$result = PMProGateway_Square::pmpro_square_create_webhooks( $environment );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Webhooks have been created', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Webhooks could not be created', 'pmpro-square' ); ?>: <?php echo esc_html_e( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}

	static function pmpro_square_create_webhooks_auto() {

		$environment = pmpro_getOption( 'gateway_environment' );
		$existing_webhooks = pmpro_getOption( 'square_webhook_' . $environment );

		if ( empty( $existing_webhooks ) && PMProGateway_Square::pmpro_is_square_ready() ) {
			PMProGateway_Square::pmpro_square_create_webhooks( $environment );
		}

	}

	/*
	 * Generate the webhooks automatically
	 * Uses Personal Access Token so webhooks can be associated with customer app and not PMPro app
	 * This requires a separate cURL request using the personal access token instead
	 */
	static function pmpro_square_create_webhooks( $environment ) {

		$webhooks = pmpro_getOption( 'square_webhook_' . $environment );
		if ( ! $webhooks && PMProGateway_Square::pmpro_is_square_ready() ) {

			PMProGateway_Square::pmpro_square_setup();

			_log( 'Creating webhooks...' );

			//$webhook_url = 'https://websitetestarea.com/?pmpro_square_webhook';
			$webhook_url = PMProGateway_Square::pmpro_square_get_webhook_url();

			$event_types = array(
				'order.created',
				'order.updated',
				'payment.created',
				'payment.updated',
				'refund.created',
				'refund.updated',
				'subscription.created',
				'subscription.updated',
			);
		
			$data = array(
				'idempotency_key' => PMProGateway_Square::pmpro_square_get_idempotency_key(),
				'subscription' => array(
					'name' => 'PMPro',
					'notification_url' => $webhook_url,
					'event_types' => $event_types,
				),
				'api_version' => '2024-12-18',
			);
		
			$response = wp_remote_post( PMProGateway_Square::$base_url . '/v2/webhooks/subscriptions', array(
				'method'    => 'POST',
				'body'      => json_encode( $data ),
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . PMProGateway_Square::$personal_access_token,
				),
			) );
					
			// Handle the response from Square
			if ( is_wp_error( $response ) ) {
				_log( $response );
				$error_message = $response->get_error_message();
				_log( "Error creating Square webhook subscription: $error_message" );
				return array( 'error' => $error_message );
			} else {
				// Log the response for debugging
				$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $response_body['subscription'] ) ) {
					_log( $response_body, "Square webhook subscription created successfully" );
					update_option( 'pmpro_square_webhook_' . $environment, $response_body['subscription'] );
					return array( 'success' => true );
				} else {
					var_dump( $response_body );
					return array( 'error' => __( 'Unknown error', 'pmpro-square' ) );
				}
			}

			return array( 'error' => __( 'Unknown error', 'pmpro-square' ) );

		}
			
	}

	/**
	 * Display fields for this gateway's options.
	 *
	 * @since 1.8
	 */
	static function pmpro_payment_option_fields( $values, $gateway ) {
	?>
	<tr class="pmpro_settings_divider gateway gateway_square" <?php if( $gateway != "square" ) { ?>style="display: none;"<?php } ?> >
		<td colspan="2">
			<h2><?php esc_html_e('Square Settings', 'pmpro-square' ); ?></h2>
			<div class="notice notice-large notice-warning inline">
					<p class="pmpro_square_notice">
						<strong><?php esc_html_e( 'Paid Memberships Pro: Square is currently in Beta.', 'pmpro-square' ); ?></strong><br />								
						<a href="https://www.paidmembershipspro.com/add-ons/square/" target="_blank"><?php esc_html_e( 'Read the documentation on getting started with Paid Memberships Pro Square &raquo;', 'pmpro-square' ); ?></a>
					</p>
				</div>
		</td>
	</tr>

	<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="square_sandbox_personal_access_token"><?php esc_html_e( 'Sandbox Access Token', 'pmpro-square' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="square_sandbox_access_token" name="square_sandbox_personal_access_token" size="60" value="<?php echo esc_attr( $values['square_sandbox_personal_access_token'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the access token ID from Square', 'pmpro-square' );?></small>
		</td>
	</tr>

	<?php if ( ! empty( $values['square_sandbox_personal_access_token'] ) ) { ?>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row">
				<label><?php esc_html_e( 'Subscriptions Status', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<?php if ( ! pmpro_getOption( 'square_subscription_plan_id' ) ) { ?>
					<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_create_default_subscription=sandbox' ), 'pmpro_square_create_default_subscription' ); ?>" class="button"><?php esc_html_e( 'Subscription Setup', 'pmpro-square' );?></a>
				<?php } else { ?>
					<div class="notice notice-success inline">
						<p><?php esc_html_e( 'Subscription capabilities enabled', 'pmpro-square' ); ?></p>
					</div>
				<?php } ?>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row">
				<label for="square_sandbox_webhooks"><?php esc_html_e( 'Sandbox Webhooks', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<?php
				$webhook = get_option( 'pmpro_square_webhook_sandbox' );
				if ( $webhook ) {
					echo '<div class="notice notice-success inline"><p>' . join( ', ', $webhook['event_types'] ) . '</p></div>';
				} else {
					echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=sandbox' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a>';
				}
				?>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_location_id"><?php esc_html_e( 'Sandbox Location', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<select id="square_sandbox_location_id" name="square_sandbox_location_id">
					<option value=""><?php esc_html_e( 'Default location', 'pmpro-square' ); ?></option>
					<?php
					$locations = get_option( 'pmpro_square_locations_sandbox' );
					if ( ! empty( $locations ) ) {
						foreach ( $locations as $id => $name ) {
							echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $values['square_sandbox_location_id'], false ) . '>' . esc_html( $name ) . '</option>';
						}
					}
					?>
				</select>
				<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=sandbox' ), 'pmpro_square_refresh_locations' ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' );?></a>
			</td>
		</tr>
	<?php } ?>

	<!--
	<?php 
	$sandbox_access_token = get_option( 'pmpro_square_sandbox_access_token' );
	if ( empty( $sandbox_access_token ) ) { ?>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_connect"><?php esc_html_e( 'Sandbox Square Connect', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<a href="<?php echo admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_oauth_connect=sandbox' ); ?>" class="button"><?php esc_html_e( 'Connect to Square', 'pmpro-square' );?></a>
			</td>
		</tr>
	<?php } else { ?>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_connect"><?php esc_html_e( 'Sandbox Square Connect', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<a href="<?php echo admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_oauth_disconnect=sandbox' ); ?>" class="button"><?php esc_html_e( 'Disconnect from Square', 'pmpro-square' );?></a>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_personal_access_token"><?php esc_html_e( 'Sandbox Access Token', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="square_sandbox_access_token" name="square_sandbox_personal_access_token" size="60" value="<?php echo esc_attr( $values['square_sandbox_personal_access_token'] ); ?>" />
				<br /><small><?php esc_html_e( 'Enter the access token ID from Square', 'pmpro-square' );?></small>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_webhooks"><?php esc_html_e( 'Sandbox Webhooks', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<?php
				$webhook = get_option( 'pmpro_square_webhook_sandbox' );
				if ( $webhook ) {
					echo join( ', ', $webhook['event_types'] );
				} else {
					echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=sandbox' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a>';
				}
				?>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_location_id"><?php esc_html_e( 'Sandbox Location', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<select id="square_sandbox_location_id" name="square_sandbox_location_id">
					<option value=""><?php esc_html_e( 'Default location', 'pmpro-square' ); ?></option>
					<?php
					$locations = get_option( 'pmpro_square_locations_sandbox' );
					if ( ! empty( $locations ) ) {
						foreach ( $locations as $id => $name ) {
							echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $values['square_sandbox_location_id'], false ) . '>' . esc_html( $name ) . '</option>';
						}
					}
					?>
				</select>
				<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=sandbox' ), 'pmpro_square_refresh_locations' ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' );?></a>
			</td>
		</tr>
	<?php } ?>
				-->

		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_live_personal_access_token"><?php esc_html_e( 'Live Access Token', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="square_live_personal_access_token" name="square_live_access_token" size="60" value="<?php echo esc_attr( $values['square_live_personal_access_token'] ); ?>" />
				<br /><small><?php esc_html_e( 'Enter the access token ID from Square', 'pmpro-square' );?></small>
			</td>
		</tr>

		<?php if ( ! empty( $values['square_live_personal_access_token'] ) ) { ?>
			<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
				<th scope="row" valign="top">
					<label for="square_live_webhooks"><?php esc_html_e( 'Sandbox Webhooks', 'pmpro-square' ); ?>:</label>
				</th>
				<td>
					<?php
					$webhook = get_option( 'pmpro_square_webhook_live' );
					if ( $webhook ) {
						echo join( ', ', $webhook['event_types'] );
					} else {
						echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=live' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a>';
					}
					?>
				</td>
			</tr>
			<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
				<th scope="row" valign="top">
					<label for="square_live_location_id"><?php esc_html_e( 'Live Location', 'pmpro-square' ); ?>:</label>
				</th>
				<td>
					<select id="square_live_location_id" name="square_live_location_id">
						<option value=""><?php esc_html_e( 'Default location', 'pmpro-square' ); ?></option>
						<?php
						$locations = get_option( 'pmpro_square_locations_live' );
						if ( ! empty( $locations ) ) {
							foreach ( $locations as $id => $name ) {
								echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $values['square_live_location_id'], false ) . '>' . esc_html( $name ) . '</option>';
							}
						}
						?>
					</select>
					<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=sandbox' ), 'pmpro_square_refresh_locations' ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' );?></a>
				</td>
			</tr>
		<?php } ?>

	<!--
	<?php 
	$live_access_token = get_option( 'pmpro_square_live_access_token' );
	if ( empty( $live_access_token ) ) { ?>	
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_sandbox_connect"><?php esc_html_e( 'Live Square Connect', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<a href="<?php echo admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_oauth_connect=live' ); ?>" class="button"><?php esc_html_e( 'Connect to Square', 'pmpro-square' );?></a>
			</td>
		</tr>
	<?php } else { ?>
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_live_connect"><?php esc_html_e( 'Live Square Connect', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<a href="<?php echo admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_oauth_disconnect=live' ); ?>" class="button"><?php esc_html_e( 'Disconnect from Square', 'pmpro-square' );?></a>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_live_personal_access_token"><?php esc_html_e( 'Live Access Token', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="square_live_personal_access_token" name="square_live_access_token" size="60" value="<?php echo esc_attr( $values['square_live_personal_access_token'] ); ?>" />
				<br /><small><?php esc_html_e( 'Enter the access token ID from Square', 'pmpro-square' );?></small>
			</td>
		</tr>
	<?php } ?>
				-->
	
	<script>
		/*
		jQuery( document ).ready( function($) {
			$( '#gateway_environment' ).on( 'change', function() {
				let environment = $( 'option:selected', this ).val();
				if ( environment == 'live' ) {
					$( '.gateway_square_live' ).show();
					$( '.gateway_square_sandbox' ).hide();
				} else {
					$( '.gateway_square_live' ).hide();
					$( '.gateway_square_sandbox' ).show();
				}
			});
		});
		*/
	</script>

	<?php
	}

	/**
	 * Check settings if billing address should be shown.
	 * @since 1.8
	 */
	public static function pmpro_include_billing_address_fields( $include ) {
		//check settings RE showing billing address
		if ( ! get_option( "pmpro_square_billingaddress" ) ) {
			$include = false;
		}

		return $include;
	}

	/**
	 * Remove required billing fields
	 */
	static function pmpro_required_billing_fields( $fields ) {

		$remove = array( 'CardType', 'AccountNumber', 'ExpirationMonth', 'ExpirationYear', 'CVV' );

		if ( ! get_option( "pmpro_square_billingaddress" ) ) {
			$remove = array_merge( $remove, [ 'baddress1', 'bemail', 'bfirstname', 'blastname', 'baddress', 'bcity', 'bstate', 'bzipcode', 'bphone', 'bcountry', 'CardType' ] );
		}

		foreach ( $remove as $field ) {
			unset( $fields[ $field ] );
		}

		return $fields;
	}

	/**
	 * Swap in our submit buttons.
	 *
	 * @since 1.8
	 */
	static function pmpro_checkout_default_submit_button( $show ) {

		global $gateway, $pmpro_requirebilling;

		//show our submit buttons
		?>
		<span id="pmpro_submit_span">
			<input type="hidden" name="submit-checkout" value="1" />
			<input type="submit" id="pmpro_btn-submit" class="<?php echo esc_attr( pmpro_get_element_class(  'pmpro_btn pmpro_btn-submit-checkout'  ) ); ?>" value="<?php if( $pmpro_requirebilling ) { esc_html_e( 'Check Out with Square', 'pmpro-square' ); } else { esc_html_e( 'Submit and Confirm', 'pmpro-square' ); } ?>" /></span>
		<?php

		//don't show the default
		return false;
	}

	/**
	 * Process checkout.
	 */
	function process( &$order ) {
		
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		// clean up a couple values
		$order->payment_type = 'Square';
		$order->CardType     = '';
		$order->cardtype     = '';
		$order->status = 'token';
		$order->saveOrder();

		pmpro_save_checkout_data_to_order( $order );

		do_action( 'pmpro_before_send_to_square', $order->user_id, $order );

		$this->sendToSquare( $order );
	}



	/// Document this.
	static function pmpro_square_get_idempotency_key( $key_input = '', $append_key_input = true ) {

		if ( '' === $key_input ) {
			$key_input = uniqid( '', false );
		}

		return substr( apply_filters( 'pmpro_square_idempotency_key', sha1( get_option( 'siteurl' ) . $key_input ) . ( $append_key_input ? ':' . $key_input : '' ) ), -40 );
	}

	/// Document this.
	static function pmpro_square_get_location_id() {

		$environment = pmpro_getOption( 'gateway_environment' );
		$location_id = pmpro_getOption( 'square_' . $environment . '_location_id' );
		if ( $location_id ) {
			return $location_id;
		} else {
			$locations = get_option( 'pmpro_square_locations_' . $environment );
			if ( ! empty( $locations ) ) {
				$default_location_id = array_key_first( $locations );
				return $default_location_id;
			}
		}
		return null;
		
	}

	function sendToSquare( &$order ) {
		global $pmpro_currency, $current_user;
		
		PMProGateway_Square::pmpro_square_setup();

		$payment_link = new \Square\Models\CreatePaymentLinkRequest();
		$payment_link->setIdempotencyKey( PMProGateway_Square::pmpro_square_get_idempotency_key() );

		$square_order = new \Square\Models\Order( PMProGateway_Square::pmpro_square_get_location_id() );
		$prepopulate = new \Square\Models\PrePopulatedData();

		// Set the customer in Square.
		if ( ! empty( $order->user_id ) ) {
			$user_id = $order->user_id;
		}
		if ( empty( $user_id ) && ! empty( $current_user->ID ) ) {
			$user_id = $current_user->ID;
		}
		if ( ! empty( $user_id ) ) {
			$user = empty( $user_id ) ? null : get_userdata( $user_id );

			// Create new customer if does not exist.
			$square_customer_id = get_user_meta( $user_id, 'pmpro_square_customer_id', true );
			if ( ! $square_customer_id ) {

				$customer_request = new \Square\Models\CreateCustomerRequest();
				$customer_request->setGivenName( $user->first_name );
				$customer_request->setFamilyName( $user->last_name );
				$customer_request->setEmailAddress( $user->user_email );
				$customer_response = PMProGateway_Square::$client->getCustomersApi()->createCustomer( $customer_request );
				if ( $customer_response->isSuccess()) {
				    $square_customer = $customer_response->getResult()->getCustomer();
					$square_customer_id = $square_customer->getId();
					_log( $square_customer, 'Customer created' );
					update_user_meta( $user_id, 'pmpro_square_customer_id', $square_customer_id );
				} else {
    				$errors = $customer_response->getErrors();
					_log( $errors, 'Customer NOT created' );
				}

			}

			if ( $square_customer_id ) {
				$square_order->setCustomerId( $square_customer_id );
			}

			$prepopulate->setBuyerEmail( $user->user_email );

		}

		$checkout_options = new \Square\Models\CheckoutOptions();
		$checkout_options->setAskForShippingAddress( false );
		$checkout_options->setRedirectUrl( add_query_arg( 'pmpro_level', $order->membership_level->id, pmpro_url( 'confirmation' ) ) );

		/*
		echo '<pre>';
		var_dump( $order );
		echo '</pre>';
		exit;
		*/
		
		// Recurring membership
		if ( pmpro_isLevelRecurring( $order->membership_level ) ) {

			// Define the initial price.
			$initial_payment = $order->subtotal;
			$initial_payment_tax = $order->getTaxForPrice( $initial_payment );
			$initial_payment = pmpro_round_price( (float) $initial_payment + (float) $initial_payment_tax );
			$initial_payment = $initial_payment * 100; // Square works in cents.

			$initial_price_money = new \Square\Models\Money();
			$initial_price_money->setAmount( $initial_payment );
			$initial_price_money->setCurrency( $pmpro_currency );
			
			$recurring_amount = pmpro_round_price( (float) $order->membership_level->billing_amount ) * 100; // Square works in cents.
			$recurring_price_money = new \Square\Models\Money();
			$recurring_price_money->setAmount( $recurring_amount );
			$recurring_price_money->setCurrency( $pmpro_currency );

			$subscription_plan_variation_id = pmpro_getOption( 'square_plan_variation_id_' . $order->membership_level->id );

			if ( ! $subscription_plan_variation_idx ) {

				// Get subscription plan ID. If not there, create it.
				$subscription_plan = pmpro_getOption( 'square_subscription_plan_id' );
				if ( empty( $subscription_plan ) ) {
					$result = pmpro_square_create_default_subscription_plan();
					if ( empty( $result['success'] ) ) {
						echo 'No primary subscription plan could be made';
						exit;
					}
					$subscription_plan = $result['plan'];
				}
			
				$recurring_pricing = new \Square\Models\SubscriptionPricing();
				$recurring_pricing->setType( 'STATIC' );
				$recurring_pricing->setPriceMoney( $recurring_price_money );

				//figure out days based on period
				if ( $order->membership_level->cycle_period == "Day" ) {
					$cadence = 'DAILY';
					//$subscription_phase->setCadence( 'DAILY' );
				} else if ( $order->membership_level->cycle_period == "Week" ) {
					$cadence = 'WEEKLY';
					//$subscription_phase->setCadence( 'WEEKLY' );
				} else if ( $order->membership_level->cycle_period == "Month" ) {
					$cadence = 'MONTHLY';
					//$subscription_phase->setCadence( 'MONTHLY' );
				} else if ( $order->membership_level->cycle_period == "Year" ) {
					$cadence = 'ANNUAL';
					//$subscription_phase->setCadence( 'ANNUAL' );
				}
				
				$subscription_phase = new \Square\Models\SubscriptionPhase( $cadence );
				$subscription_phase->setOrdinal( 0 ); // The order in which the phase is to be processed.
				//$subscription_phase->setPricing( $pricing );
				//$subscription_phase->setRecurringPriceMoney( $recurring_price_money );
				$subscription_phase->setPricing( $recurring_pricing );

				$number_of_rebills = '';
				if ( ! empty( $order->membership_level->billing_limit ) ) {
					$number_of_rebills = $order->membership_level->billing_limit;
				}
				if ( $number_of_rebills ) {
					$subscription_phase->setPeriods( $number_of_rebills );
				}
	
				$phases = array( $subscription_phase );

				$subscription_plan_variation_data = new \Square\Models\CatalogSubscriptionPlanVariation( $order->membership_level->name, $phases );
				$subscription_plan_variation_data->setSubscriptionPlanId( $subscription_plan );
				
				$object = new \Square\Models\CatalogObject( 'SUBSCRIPTION_PLAN_VARIATION', '#1' );
				$object->setSubscriptionPlanVariationData( $subscription_plan_variation_data );

				$body = new \Square\Models\UpsertCatalogObjectRequest( PMProGateway_Square::pmpro_square_get_idempotency_key(), $object );

				$api_response = PMProGateway_Square::$client->getCatalogApi()->upsertCatalogObject( $body );

				if ( $api_response->isSuccess() ) {
					$catalog_object = $api_response->getResult()->getCatalogObject();
					$subscription_plan_variation_id = $catalog_object->getId();
					pmpro_setOption( 'square_plan_variation_id_' . $order->membership_level->id, $subscription_plan_variation_id );
				} else {
					$errors = $api_response->getErrors();
					echo 'No subscription plan variation: ';
					var_dump( $errors );
					_log( $errors, 'No subscription plan variation' );
					exit;
				}

			}

			$checkout_options->setSubscriptionPlanId( $subscription_plan_variation_id );
			
			// The initial price is part of this quick pay part. The reecurring price is tied to the subscription phases.
			$quick_pay = new \Square\Models\QuickPay(
				$order->membership_level->name,
				$initial_price_money,
				PMProGateway_Square::pmpro_square_get_location_id(),
			);
			$payment_link->setQuickPay( $quick_pay );
		
		} else {	
		
			// If one-time payment, create basic payment link with a line item.

			// Define the one-time price.
			$initial_payment = $order->subtotal;
			$initial_payment_tax = $order->getTaxForPrice( $initial_payment );
			$initial_payment = pmpro_round_price( (float) $initial_payment + (float) $initial_payment_tax );
			$initial_payment = $initial_payment * 100; // Square works in cents.

			$price_money = new \Square\Models\Money();
			$price_money->setAmount( $initial_payment );
			$price_money->setCurrency( $pmpro_currency );

			$order_line_item = new \Square\Models\OrderLineItem( '1' );
			$order_line_item->setName( $order->membership_level->name );
			$order_line_item->setBasePriceMoney( $price_money );
			
			$line_items = [
				$order_line_item,
			];
			$square_order->setLineItems( $line_items );
			$payment_link->setOrder( $square_order );

		}
	
		$payment_link->setPrePopulatedData( $prepopulate );
		$payment_link->setCheckoutOptions( $checkout_options );

		$api_response = PMProGateway_Square::$client->getCheckoutApi()->createPaymentLink( $payment_link );
			
		if ( $api_response->isSuccess() ) {
			$result = $api_response->getResult()->getPaymentLink();
			$url = $result->getUrl();
			update_pmpro_membership_order_meta( $order->id, 'square_order_id', $result->getOrderId() );
			_log( 'Created Square order: ' . $result->getOrderId() );
			wp_redirect( $url );
			exit;
		} else {
			$errors = $api_response->getErrors();
			_log( $errors );
			var_dump( $errors );
			exit;
		}

	}

	static function webhook_listener() {
		global $wpdb;

		if ( empty( $_GET['pmpro_square_webhook'] ) ) {
			return;
		}

		_log( '========= WEBHOOK LISTENER' );

		$environment = get_option( 'pmpro_gateway_environment' );
		$webhook_data = get_option( 'pmpro_square_webhook_' . $environment );
		if ( empty( $webhook_data ) ) {
			_log( 'No webhook settings data' );
			http_response_code( 403 );
			exit;
		}

		$headers = apache_request_headers();
		_log( $headers, 'HEADERS' );
		$signature = $headers["X-Square-Hmacsha256-Signature"];
		
		// Signature completely empty (Throw a warning/bail) /// Temporary.
		if ( empty( $signature ) ) {
			_log( 'No signature' );
			http_response_code( 403 );
			exit;
		}

		$body = '';   
		$handle = fopen( 'php://input', 'r' );
		while( ! feof( $handle ) ) {
			$body .= fread( $handle, 1024 );
		}

		if ( ! \Square\Utils\WebhooksHelper::isValidWebhookEventSignature( $body, $signature, $webhook_data['signature_key'], PMProGateway_Square::pmpro_square_get_webhook_url() ) ) {
			_log( "Error verifying webhook signature" );
			http_response_code( 403 );
			exit;
		}

		$webhook = json_decode( $body, true );

		if ( $webhook['type'] === 'payment.updated' ) {

			_log( $webhook, 'SQUARE WEBHOOK RECEIVED ============================' );

			$payment = $webhook['data']['object']['payment'];

			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT pmpro_membership_order_id FROM $wpdb->pmpro_membership_ordermeta WHERE meta_key = 'square_order_id' AND meta_value = %s LIMIT 1", $payment['order_id'] ) );
			if ( empty( $order_id ) ) {
				_log( 'Webhook could not find order with square order ID: ' . $payment['id'] );
				exit();
			}
	
			$order = new MemberOrder( $order_id );
	
			if ( $payment['status'] !== 'COMPLETED' ) {
				return;
			}

			$order->payment_transaction_id = $payment['id'];
			$order->saveOrder();

			pmpro_pull_checkout_data_from_order( $order );
			pmpro_complete_async_checkout( $order );

			_log( '=========== ORDER SAVED!' );
		}

		http_response_code( 200 );
		exit;

	}

}
