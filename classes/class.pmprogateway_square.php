<?php
use Square\SquareClientBuilder;
use Square\Authentication\BearerAuthCredentialsBuilder;
use Square\Environment;

//load classes init method
add_action( 'init', function(){
	$pmpro_square = new PMProGateway_square();
	$pmpro_square->init();
} );

class PMProGateway_square extends PMProGateway {

	// Use this to interact with the Square API via SDK.
	private $environment;
	private $base_url;
	//private static $subscription_plan_id;
	private $application_id;
	private $location_id;
	private $personal_access_token;
	private $client;
	private $log_file;

	function __construct( $gateway = NULL ) {

		$this->gateway = $gateway;
		$this->gateway_environment = get_option( "pmpro_gateway_environment" );

		$this->log_file = $this->get_log_file_path();

		return $this->gateway;

	}

	/**
	 * Kick things off and setup all needed hooks
	 */
	public function init() {
		
		// Make sure Square is a gateway option.
		add_filter( 'pmpro_gateways', array( $this, 'pmpro_gateways' ));
		add_filter( 'pmpro_gateways_with_pending_status', array( $this, 'pmpro_gateways_with_pending_status' ) );

		// Add fields to payment settings.
		add_filter( 'pmpro_payment_options', array( $this, 'pmpro_payment_options' ));
		add_filter( 'pmpro_payment_option_fields', array( $this, 'pmpro_payment_option_fields' ), 10, 2);

		add_action( 'pmpro_after_saved_payment_options', array( $this, 'refresh_locations_auto' ) );
		add_action( 'pmpro_after_saved_payment_options', array( $this, 'create_webhooks_auto' ) );

		add_action( 'wp_ajax_nopriv_pmpro_square_webhook', array( $this, 'webhook_listener' ) );
		add_action( 'wp_ajax_pmpro_square_webhook', array( $this, 'webhook_listener' ) );

		$gateway = pmpro_getGateway();

		if ( $gateway == "square" ) {		

			// Enqueue Square JS on checkout page.
			add_action( 'pmpro_after_checkout_preheader', array( $this, 'enqueue_scripts' ) );
			add_action( 'pmpro_billing_preheader', array( $this, 'enqueue_scripts' ) );

			add_filter( 'pmpro_required_billing_fields', array( $this, 'required_billing_fields' ) );
			add_action( 'admin_notices', array( $this, 'refresh_locations_manual' ) );
			add_action( 'admin_notices', array( $this, 'create_webhooks_manual' ) );
			add_action( 'admin_notices', array( $this, 'disable_webhooks_manual' ) );

			add_filter( 'pmpro_include_billing_address_fields', array( $this, 'include_billing_address_fields' ) );
			add_filter( 'pmpro_include_payment_information_fields', array( $this, 'include_payment_information_fields' ) );	

			add_filter( 'pmpro_after_checkout_preheader', array( $this, 'clear_pmpro_review' ) );			

			add_filter( 'pmpro_after_update_billing', array( $this, 'update_billing_card' ), 10, 2 );	

			add_action( 'pmpro_square_check_webhooks_status', array( $this, 'check_webhooks' ) );
	
		}
	}

	/**
	 * Make sure this gateway is in the gateways list
	 */
	public function pmpro_gateways( $gateways ) {

		if ( empty( $gateways['square'] ) ) {
			$gateways['square'] = __( 'Square', 'pmpro-square' );
		}

		return $gateways;
	}

	/**
	 * Say what this gateway supports
	 */
	public static function supports( $feature ) {
		$supports = array(
			'subscription_sync' => true,
			'payment_method_updates' => 'individual',
			//'check_token_orders' => true,
		);

		if ( empty( $supports[$feature] ) ) {
			return false;
		}

		return $supports[$feature];
	}

	/**
	 * Get a list of payment options that the this gateway needs/supports.
	 */
	public static function getGatewayOptions() {

		$options = array(
			'sslseal',
			'nuclear_HTTPS',
			'gateway_environment',
			'square_sandbox_application_id',
			'square_sandbox_personal_access_token',
			'square_sandbox_location_id',
			'square_live_application_id',
			'square_live_personal_access_token',
			'square_live_location_id',
			'square_billingaddress',
			'square_log',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate'
		);

		return $options;
	}

	/**
	 * Set payment options for payment settings page.
	 */
	public function pmpro_payment_options( $options ) {
		//get square options
		$square_options = $this->getGatewayOptions();

		//merge with others.
		$options = array_merge( $square_options, $options );

		return $options;
	}

	/**
	 * Setup the connection to the API client and get base options.
	 */
	private function setup( $environment = '' ) {

		if ( empty( $environment ) ) {
			$environment = get_option( 'pmpro_gateway_environment' );
		}

		if ( $environment == 'live' ) {
			$this->gateway_environment = 'live';
			$this->base_url = 'https://connect.squareup.com';
			$this->application_id = get_option( 'pmpro_square_live_application_id' );
			$this->location_id = $this->get_location_id( 'live' );
			$this->personal_access_token = get_option( 'pmpro_square_live_personal_access_token' );
		} else {
			$this->gateway_environment = 'sandbox';
			$this->base_url = 'https://connect.squareupsandbox.com';
			$this->application_id = get_option( 'pmpro_square_sandbox_application_id' );
			$this->location_id = $this->get_location_id( 'sandbox' );
			$this->personal_access_token = get_option( 'pmpro_square_sandbox_personal_access_token' );
		}
		if ( empty( $this->personal_access_token ) ) {
			return; // Don't proceed, we don't have the proper credentials.
		}
		$this->client = SquareClientBuilder::init()
		->bearerAuthCredentials(
			BearerAuthCredentialsBuilder::init( $this->personal_access_token )
		)
		->environment( ( $environment == 'live' ) ? Environment::PRODUCTION : Environment::SANDBOX )
		->build();
		
	}

	/**
	 * Check if we have the basic necessary data to make an API connection.
	 */
	private function is_ready( $ready = false ){

		$environment = get_option( 'pmpro_gateway_environment' );
		if ( $environment == 'live' ) {
			$access_token = get_option( 'pmpro_square_live_personal_access_token' );
		} else {
			$access_token = get_option( 'pmpro_square_sandbox_personal_access_token' );
		}

		$ready = false;
		if ( $access_token ) {
			$ready = true;
		} 

		return $ready;

	}

	/**
	 * Gets the current environment.
	 */
	private function get_environment() {

		if ( ! empty( $this->gateway_environment ) ) {
			return $this->gateway_environment;
		}

		// Should have already been grabbed on init but just in case.
		$this->gateway_environment = get_option( 'pmpro_gateway_environment' );
		return $this->gateway_environment;

	}

	 /**
	 * Create a unique key for API calls.
	 */
	private function get_idempotency_key( $key_input = '', $append_key_input = true ) {

		if ( '' === $key_input ) {
			$key_input = uniqid( '', false );
		}

		return substr( apply_filters( 'pmpro_square_idempotency_key', sha1( get_option( 'siteurl' ) . $key_input ) . ( $append_key_input ? ':' . $key_input : '' ) ), -40 );
	}

	/**
	 * Getting the application ID.
	 */
	private function get_application_id() {

		if ( ! empty( $this->application_id ) ) {
			return $this->application_id;
		}

		// Should have already been grabbed on init but just in case.
		$this->application_id = get_option( 'pmpro_square_' . $this->get_environment() . '_application_id' );
		if ( $this->application_id ) {
			return $this->application_id;
		}
		return null;
		
	}

	/**
	 * Getting the location ID.
	 */
	private function get_location_id( $environment = '' ) {

		if ( ! empty( $this->location_id ) ) {
			return $this->location_id;
		}

		// Should have already been grabbed on init but just in case.
		$this->location_id = get_option( 'pmpro_square_' . $this->get_environment() . '_location_id' );
		if ( $this->location_id ) {
			return $this->location_id;
		} else {
			$locations = get_option( 'pmpro_square_locations_' . $this->get_environment() );
			if ( ! empty( $locations ) ) {
				// Use the first one as the default.
				$this->location_id = array_key_first( $locations );
				return $this->location_id;
			}
		}
		return null;
		
	}

	/**
	 * Refreshes the list of available locations for an environment on a manual request.
	 */
	public function refresh_locations_manual() {

		if ( !empty( $_GET['pmpro_square_refresh_locations'] ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_refresh_locations' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_refresh_locations', 'GET' );
		$result = $this->refresh_locations( $environment );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Locations have been refreshed', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Locations could not be refreshed', 'pmpro-square' ); ?>: <?php echo esc_html( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}

	/**
	 * Refreshes the list of available locations automatically when the payment gateway settings are saved.
	 */
	public function refresh_locations_auto() {

		$existing_locations = get_option( 'pmpro_square_locations_' . $this->get_environment() );

		if ( empty( $existing_locations ) ) {
			$this->refresh_locations( $this->get_environment() );
		}

	}

	/**
	 * Does API request to get and save the available locations.
	 */
	private function refresh_locations( $environment ) {

		if ( $this->is_ready() ) {
			$this->log( 'Refreshing square locations in ' . $environment );
			$this->setup( $environment );
			$api_response = $this->client->getLocationsApi()->listLocations();
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

	/**
	 * Build URL for the webhook.
	 */
	private function get_webhook_url() {
		return admin_url( 'admin-ajax.php' ) . '?action=pmpro_square_webhook';
	}

	/**
	 * Process manual request to create webhooks for the selected environment.
	 */
	public function create_webhooks_manual() {

		if ( empty( $_GET['pmpro_square_webhooks'] ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_webhooks' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_webhooks', 'GET' );
		$result = $this->create_webhooks( $environment );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Webhooks have been created', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Webhooks could not be created', 'pmpro-square' ); ?>: <?php echo esc_html( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}

	/**
	 * Process manual request to disable webhooks for the selected environment.
	 */
	public function disable_webhooks_manual() {

		if ( empty( $_GET['pmpro_square_disable_webhooks'] ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_disable_webhooks' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_disable_webhooks', 'GET' );
		$result = $this->disable_webhooks( $environment );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Webhooks have been disabled', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Webhooks could not be disabled', 'pmpro-square' ); ?>: <?php echo esc_html( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}


	/**
	 * Runs the webhook creation automatically when payment settings are saved.
	 */
	public function create_webhooks_auto() {

		$existing_webhooks = get_option( 'pmpro_square_webhook_' . $this->get_environment() );
		if ( empty( $existing_webhooks ) ) {
			$this->create_webhooks( $this->get_environment() );
		}

	}

	/**
	 * The check done on the webhooks done on the cron.
	 */
	public function check_webhooks() {

		$this->log( 'Checking webhooks via cron...' );
		
		if ( $this->is_ready() ) {

			$this->setup( $this->get_environment() );

			$existing_webhook = get_option( 'pmpro_square_webhook_' . $this->get_environment() );
			$this->log( $existing_webhook, 'Existing webhook' );

			// If we do not have any existing webhooks saved in our settings, let's create some.
			if ( empty( $existing_webhook ) ) {
				$this->create_webhooks( $this->get_environment() );
				return;
			}

			$api_response = $this->client->getWebhookSubscriptionsApi()->listWebhookSubscriptions();
		
			if ( $api_response->isSuccess() ) {
				$webhooks = $api_response->getResult()->getSubscriptions();
				$this->log( $webhooks, 'Square webhooks via API' );

				// If no webhooks in Square, create it now.
				if ( empty( $webhooks ) ) {
					$this->create_webhooks( $this->get_environment() );
					return;
				}

				// Log or process the webhooks status
				foreach ( $webhooks as $webhook ) {
					// See if the webhook ID from our saved settings matches this webhook.
					if ( $webhook->getId() == $existing_webhook['id'] ) {
						$this->log( 'Existing webhooks confirmed by ID' );
						return;
					}
					// Compare if the notification URL is the same. If so, we have a webhook but not in settings for some reason so save to settings.
					if ( $webhook->getNotificationUrl() == $existing_webhook['notification_url'] ) {
						$this->log( 'Existing webhooks confirmed by notification URL' );
						$webhook_data = (array) $webhook->jsonSerialize();
						update_option( 'pmpro_square_webhook_' . $this->get_environment(), $webhook_data );
						return;
					}
				}

				// We do not have a matching webhook, so let's create one
				$this->create_webhooks( $this->get_environment() );
				return;

			} else {
				$errors = $api_response->getErrors();
				// Log any errors that occur
				$this->log( 'Error checking webhooks: ' . print_r( $errors, true ) );
			}

		}

	}

	/*
	 * Creating the webooks via API
	 */
	private function create_webhooks( $environment ) {

		if ( $this->is_ready() ) {

			$this->log( 'Creating webhooks in ' . $environment . '...' );

			$this->setup( $environment );

			$webhook_url = $this->get_webhook_url();

			$event_types = array(
				'order.created',
				'order.updated',
				'payment.created',
				'payment.updated',
				'refund.created',
				'refund.updated',
				'subscription.created',
				'subscription.updated',
				'invoice.payment_made',
			);
		
			$data = array(
				'idempotency_key' => $this->get_idempotency_key(),
				'subscription' => array(
					'name' => 'PMPro',
					'notification_url' => $webhook_url,
					'event_types' => $event_types,
				),
				'api_version' => '2024-12-18',
			);
		
			$response = wp_remote_post( $this->base_url . '/v2/webhooks/subscriptions', array(
				'method'    => 'POST',
				'body'      => json_encode( $data ),
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->personal_access_token,
				),
			) );
					
			// Handle the response from Square
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				$this->log( "Error creating Square webhook subscription: $error_message" );
				return array( 'error' => $error_message );
			} else {
				// Log the response for debugging
				$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $response_body['subscription'] ) ) {
					$this->log( "Square webhook subscription created successfully" );
					update_option( 'pmpro_square_webhook_' . $environment, $response_body['subscription'] );
					return array( 'success' => true );
				} else {
					$this->log( $response_body, 'Unknown error creating webhooks' );
					return array( 'error' => __( 'Unknown error creating webhooks', 'pmpro-square' ) );
				}
			}

			return array( 'error' => __( 'Unknown error creating webhooks', 'pmpro-square' ) );

		}
			
	}

	/*
	 * Creating the webooks via API
	 */
	private function disable_webhooks( $environment ) {

		if ( $this->is_ready() ) {

			$webhooks = get_option( 'pmpro_square_webhook_' . $environment );
			if ( empty( $webhooks ) ) {
				return array( 'error' => __( 'No known webhooks to disable', 'pmpro-square' ) );
			}

			$this->log( 'Disabling webhooks in ' . $environment . '...' );

			$this->setup( $environment );

			$api_response = $this->client->getWebhookSubscriptionsApi()->deleteWebhookSubscription( $webhooks['id'] );

			if ( $api_response->isSuccess() ) {
				delete_option( 'pmpro_square_webhook_' . $environment );
				return array( 'success' => true );
			} else {
				$errors = $api_response->getErrors();
				$this->log( 'Failed disabling webhooks: ' . print_r( $errors, 1 ) );
				if ( $errors[0]->getCode() == 'NOT_FOUND' ) {
					// If we cannot find any then let's at least delete what we have on record now and call it a win.
					delete_option( 'pmpro_square_webhook_' . $environment );
					return array( 'success' => true );
				}
				return array( 'error' => $errors[0]->getCode() );
			}

		}
			
	}


	/**
	 * Display fields for this gateway's options.
	 */
	public function pmpro_payment_option_fields( $values, $gateway ) {
	?>
	<tr class="pmpro_settings_divider gateway gateway_square" style="">
		<td colspan="2">
			<hr>
			<h2><?php esc_html_e( 'Square Sandbox Settings', 'pmpro-square' ); ?></h2>
		</td>
	</tr>	
	<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="square_sandbox_application_id"><?php esc_html_e( 'Sandbox Application ID', 'pmpro-square' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="square_sandbox_application_id" name="square_sandbox_application_id" size="60" value="<?php echo esc_attr( $values['square_sandbox_application_id'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Application ID from Square', 'pmpro-square' );?></small>
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
				<br /><small><?php esc_html_e( 'A location is where your transactions occur. Select "Default Location" if you are not sure.', 'pmpro-square' );?></small>
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
					echo '<div class="notice notice-success inline"><p>';
					esc_html_e( join( ', ', $webhook['event_types'] ) );
					echo '<br><a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_disable_webhooks=sandbox' ), 'pmpro_square_disable_webhooks' ) . '">' . esc_html__( 'Disable webhooks', 'pmpro-square' ) . '</a>';
					echo '</p></div>';
				} else {
					echo '<p><a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=sandbox' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a></p>';
					echo '<p>' . __( 'Webhook URL', 'pmpro-square' ) . ': <code>' . $this->get_webhook_url() . '</code></p>';
				}
				?>
			</td>
		</tr>
	<?php } ?>

	<tr class="pmpro_settings_divider gateway gateway_square" style="">
		<td colspan="2">
			<hr>
			<h2><?php esc_html_e( 'Square Live Settings', 'pmpro-square' ); ?></h2>
		</td>
	</tr>	
	<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="square_live_application_id"><?php esc_html_e( 'Live Application ID', 'pmpro-square' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="square_live_application_id" name="square_live_application_id" size="60" value="<?php echo esc_attr( $values['square_live_application_id'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Application ID from Square', 'pmpro-square' );?></small>
		</td>
	</tr>
	<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="square_live_personal_access_token"><?php esc_html_e( 'Live Access Token', 'pmpro-square' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="square_live_personal_access_token" name="square_live_personal_access_token" size="60" value="<?php echo esc_attr( $values['square_live_personal_access_token'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the access token ID from Square', 'pmpro-square' );?></small>
		</td>
	</tr>

	<?php if ( ! empty( $values['square_live_personal_access_token'] ) ) { ?>
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_live_location_id"><?php esc_html_e( 'Live Location', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<select id="square_live_location_id" name="square_live_location_id">
					<option value=""><?php esc_html_e( 'Default Location', 'pmpro-square' ); ?></option>
					<?php
					$locations = get_option( 'pmpro_square_locations_live' );
					if ( ! empty( $locations ) ) {
						foreach ( $locations as $id => $name ) {
							echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $values['square_live_location_id'], false ) . '>' . esc_html( $name ) . '</option>';
						}
					}
					?>
				</select>
				<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=live' ), 'pmpro_square_refresh_locations' ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' );?></a>
				<br /><small><?php esc_html_e( 'A location is where your transactions occur. Select "Default Location" if you are not sure.', 'pmpro-square' );?></small>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_live_webhooks"><?php esc_html_e( 'Live Webhooks', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
			<?php
				$webhook = get_option( 'pmpro_square_webhook_live' );
				if ( $webhook ) {
					echo '<div class="notice notice-success inline"><p>';
					esc_html_e( join( ', ', $webhook['event_types'] ) );
					echo '<br><a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_disable_webhooks=live' ), 'pmpro_square_disable_webhooks' ) . '">' . esc_html__( 'Disable webhooks', 'pmpro-square' ) . '</a>';
					echo '</p></div>';
				} else {
					echo '<p><a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=live' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a></p>';
					echo '<p>' . __( 'Webhook URL', 'pmpro-square' ) . ': <code>' . $this->get_webhook_url() . '</code></p>';
				}
				?>
			</td>
		</tr>
	<?php } ?>
	
	<tr class="pmpro_settings_divider gateway gateway_square" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?>>
		<td colspan="2">
			<hr />
			<h2><?php esc_html_e( 'Other Square Settings', 'pmpro-square' ); ?></h2>
		</td>
	</tr>
	<tr class="gateway gateway_square" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label for="square_billingaddress"><?php esc_html_e( 'Show Billing Address Fields in PMPro Checkout Form', 'pmpro-square' ); ?></label>
		</th>
		<td>
			<select id="square_billingaddress" name="square_billingaddress">
				<option value="0"
						<?php if ( empty( $values['square_billingaddress'] ) ) { ?>selected="selected"<?php } ?>><?php esc_html_e( 'No', 'pmpro-square' ); ?></option>
				<option value="1"
						<?php if ( ! empty( $values['square_billingaddress'] ) ) { ?>selected="selected"<?php } ?>><?php esc_html_e( 'Yes', 'pmpro-square' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( "Square doesn't require billing address fields. Choose 'No' to hide them on the checkout page.", 'pmpro-square' ); ?></p>
		</td>
	</tr>
	<tr class="gateway gateway_square" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label for="square_log"><?php esc_html_e( 'Logging', 'pmpro-square' ); ?></label>
		</th>
		<td>
			<label><input type="checkbox" name="square_log" <?php checked( 'yes', $values['square_log'] ); ?> value="yes"> <?php _e( 'Enable logging of all Square events', 'pmpro-square' ); ?></label>
			<?php if ( ! empty( $values['square_log'] ) ) { ?>
				<?php $log_file_name = get_option( 'pmpro_square_log_file_name' ); ?>
				<br><br><a href="../wp-content/uploads/<?php echo esc_attr( $log_file_name ); ?>" target="_blank" class="button"><?php esc_html_e( 'View log file', 'pmpro-square' ); ?></a>
			<?php } ?>
		</td>
	</tr>
	<?php
	}

	public function enqueue_scripts() {
		global $gateway, $pmpro_level, $current_user, $pmpro_requirebilling, $pmpro_pages, $pmpro_currency;

		$this->setup();

		// Prep JavaScript vars to work with either CHARGE (new payment) or STORE (update billing card).
		$initial_payment = 0;
		$intent = 'STORE';
		$level_id = '';
		if ( pmpro_is_checkout() ) {
			$initial_payment = floatval( $pmpro_level->initial_payment );
			$intent = 'CHARGE';
			$level_id = $pmpro_level->id;
		}
		
		if ( $this->get_environment() == 'live' ) {
			wp_enqueue_script( 'pmpro-square', 'https://web.squarecdn.com/v1/square.js' );
		} else {
			wp_enqueue_script( 'pmpro-square', 'https://sandbox.web.squarecdn.com/v1/square.js' );
		}
		wp_enqueue_script( 'pmpro-square-processing', PMPRO_SQUARE_URL . 'assets/js/square-processing.js', array( 'jquery' ), time(), true );
		wp_localize_script(
			'pmpro-square-processing',
			'pmpro_square_vars',
			array(
				'application_id' => $this->application_id,
				'location_id'    => $this->location_id,
				'level_id'       => $level_id,
				'intent'         => $intent,
				'amount'         => $initial_payment,
				'currency'       => $pmpro_currency,
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'security'       => wp_create_nonce( 'pmpro_square' ),
			)
		);
	}

	/**
	 * Check settings if billing address should be shown.
	 */
	public function include_billing_address_fields( $include ) {
		//check settings RE showing billing address
		if ( ! get_option( "pmpro_square_billingaddress" ) ) {
			$include = false;
		}

		return $include;
	}

	/**
	 * Remove required billing fields
	 */
	public function required_billing_fields( $fields ) {
		global $current_user, $bemail, $bconfirmemail;

		$remove = array( 'CardType', 'AccountNumber', 'ExpirationMonth', 'ExpirationYear', 'CVV' );

		if ( ! get_option( "pmpro_square_billingaddress" ) ) {
			$remove = array_merge( $remove, [ 'baddress1', 'bemail', 'bfirstname', 'blastname', 'baddress', 'bcity', 'bstate', 'bzipcode', 'bphone', 'bcountry', 'CardType' ] );
		}

		// If a user is logged in, don't require bemail either.
		if ( ! empty( $current_user->user_email ) ) {
			$remove        = array_merge( $remove, [ 'bemail' ] );
			$bemail        = $current_user->user_email;
			$bconfirmemail = $bemail;
		}
		
		foreach ( $remove as $field ) {
			unset( $fields[ $field ] );
		}

		return $fields;
	}

	/**
	 * Use our own payment fields at checkout.
	 */
	public function include_payment_information_fields() {
		?>
		<fieldset id="pmpro_payment_information_fields" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset', 'pmpro_payment_information_fields' ) ); ?>">
			<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card' ) ); ?>">
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
					<legend class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_legend' ) ); ?>">
						<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_heading pmpro_font-large' ) ); ?>"><?php esc_html_e('Payment Information', 'pmpro-square' ); ?></h2>
					</legend>
					<div id="pmpro-square-card-container">
						<div id="pmpro-square-card-fields"></div>
						<div id="pmpro-square-status"></div>
					</div>
				</div> <!-- end pmpro_card_content -->
			</div> <!-- end pmpro_card -->
		</fieldset> <!-- end pmpro_payment_information_fields -->
		<?php

		//don't include the default
		return false;
	}

	/**
	 * Every plan must have at least one variation.
	 * This compares the passed membership level to existing saved variation data to see if it is different
	 */
	private function get_subscription_plan_variation_id( $membership_level, $order ) {

		// Keys to compare between membership level and existing subscription plans.
		$membership_fields_to_compare = array(
			'initial_payment',
			'billing_amount',
			'cycle_number',
			'cycle_period',
			'billing_limit',
			'trial_amount',
			'trial_limit',
			'expiration_number',
			'expiration_period',
		);
	
		// Convert membership level to array for comparison.
		$membership_level_data = (array) $membership_level;
	
		// Filter $membership_level_data to only include relevant keys and values.
		$filtered_membership_data = array_intersect_key( $membership_level_data, array_flip( $membership_fields_to_compare ) );
	
		// Get existing subscription plan variations.
		$existing_subscription_plan_variations = get_option( 'pmpro_square_subscription_plan_variations_' . $this->get_environment() . '_' . $membership_level->id );
	
		// Check if there are existing variations.
		if ( ! empty( $existing_subscription_plan_variations ) && is_array( $existing_subscription_plan_variations ) ) {
			foreach ( $existing_subscription_plan_variations as $square_plan_variation_id => $plan_variation ) {
	
				// Filter the plan variation to include only the keys we're comparing.
				$filtered_plan_variation = array_intersect_key( (array) $plan_variation, array_flip( $membership_fields_to_compare ) );
	
				// Compare both keys and values for equality.
				if ( $filtered_membership_data === $filtered_plan_variation ) {
					// If a match is found, return the plan variation ID.
					$this->log( 'Returning existing plan variation ID: ' . $square_plan_variation_id );
					return $square_plan_variation_id;
				}
			}
		}

		$this->log( 'No existing plan variations found' );
	
		return false;
	}

	/**
	 * Creates a subscription plan in Square
	 **/
	private function create_subscription_plan( $membership_level ) {

		$this->log( 'Starting create_subscription_plan...' );

		$subscription_plan_data = new \Square\Models\CatalogSubscriptionPlan( $membership_level->name );
		$subscription_plan_data->setAllItems( false );

		$object = new \Square\Models\CatalogObject( 'SUBSCRIPTION_PLAN', '#' . $membership_level->id );
		$object->setSubscriptionPlanData( $subscription_plan_data );

		$body = new \Square\Models\UpsertCatalogObjectRequest( 'paid-memberships-pro-' . $membership_level->id, $object );
		$body->setIdempotencyKey( $this->get_idempotency_key() );

		$api_response = $this->client->getCatalogApi()->upsertCatalogObject( $body );

		if ( $api_response->isSuccess() ) {
			$catalog_object = $api_response->getResult()->getCatalogObject();
			update_option( 'pmpro_square_subscription_plan_id_' . $this->get_environment() . '_' . $membership_level->id, $catalog_object->getId() );
			$this->log( 'Created new subscription plan: ' . $catalog_object->getId() );
			return $catalog_object->getId();
		} else {
			$this->log( $api_response, 'Failed to create subscription plan' );
			return false;
		}

	}

	/**
	 * Prep the phase data when creating a new subscription.
	 **/
	private function create_subscription_phases( $membership_level, $order ) {
		global $pmpro_currency;

		$this->log( 'Starting create_subscription_phases...' );

		$phases = array();

		$subscription_delay  = get_option( 'pmpro_subscription_delay_' . $order->membership_level->id , '' );

		// Figure out cadence based on cycle period.
		if ( $membership_level->cycle_period == "Day" ) {
			$cycle_period = 'DAILY';
		} else if ( $membership_level->cycle_period == "Week" ) {
			$cycle_period = 'WEEKLY';
		} else if ( $membership_level->cycle_period == "Month" ) {
			$cycle_period = 'MONTHLY';
		} else if ( $membership_level->cycle_period == "Year" ) {
			$cycle_period = 'ANNUAL';
		}

		$ordinal = 0; // Phase number in the sequence.

		// Define the initial price.
		if ( $order->subtotal ) {
			$initial_payment = $order->subtotal;
			// We will pass the tax % later so do not need to add it to the total here.
			$initial_payment = $initial_payment * 100; // Square works in cents.

			$initial_price_money = new \Square\Models\Money();
			$initial_price_money->setAmount( $initial_payment );
			$initial_price_money->setCurrency( $pmpro_currency );

			$initial_pricing = new \Square\Models\SubscriptionPricing();
			$initial_pricing->setType( 'STATIC' );
			$initial_pricing->setPriceMoney( $initial_price_money );

			// If we have subscription delay, use that many days for this initial payment phase.
			if ( $subscription_delay ) {
				$profile_start_date = pmpro_calculate_profile_start_date( $order, 'U' );
				$subscription_delay_days = ceil( abs( $profile_start_date - time() ) / 86400 );
				$initial_phase = new \Square\Models\SubscriptionPhase( 'DAILY' );
				$initial_phase->setPeriods( $subscription_delay_days );
			} else {
				$initial_phase = new \Square\Models\SubscriptionPhase( $cycle_period );
				$initial_phase->setPeriods( 1 );
			}

			$initial_phase->setOrdinal( $ordinal++ ); // The order in which the phase is to be processed.
			$initial_phase->setPricing( $initial_pricing );

			$phases[] = $initial_phase;
		}

		// Add the trial if set and we do not have a subscription delay.
		if ( empty( $subscription_delay ) && $membership_level->trial_amount && $membership_level->trial_limit ) {
			$trial_payment = $membership_level->trial_amount;
			// We will pass the tax % later so do not need to add it to the total here.
			$trial_payment = $trial_payment * 100; // Square works in cents.

			$trial_price_money = new \Square\Models\Money();
			$trial_price_money->setAmount( $trial_payment );
			$trial_price_money->setCurrency( $pmpro_currency );

			$trial_pricing = new \Square\Models\SubscriptionPricing();
			$trial_pricing->setType( 'STATIC' );
			$trial_pricing->setPriceMoney( $trial_price_money );

			$trial_phase = new \Square\Models\SubscriptionPhase( $cycle_period );
			$trial_phase->setOrdinal( $ordinal++ ); // The order in which the phase is to be processed.
			$trial_phase->setPricing( $trial_pricing );
			$trial_phase->setPeriods( intval( $membership_level->trial_limit ) );

			$phases[] = $trial_phase;
		}
		
		$recurring_payment = pmpro_round_price( (float) $membership_level->billing_amount );
		// We will pass the tax % later so do not need to add it to the total here.
		$recurring_payment = $recurring_payment * 100; // Square works in cents.

		$recurring_price_money = new \Square\Models\Money();
		$recurring_price_money->setAmount( $recurring_payment );
		$recurring_price_money->setCurrency( $pmpro_currency );

		$recurring_pricing = new \Square\Models\SubscriptionPricing();
		$recurring_pricing->setType( 'STATIC' );
		$recurring_pricing->setPriceMoney( $recurring_price_money );
		
		$subscription_phase = new \Square\Models\SubscriptionPhase( $cycle_period );
		$subscription_phase->setOrdinal( $ordinal++ ); // The order in which the phase is to be processed.
		$subscription_phase->setPricing( $recurring_pricing );

		// Set max number of billing periods.
		if ( ! empty( $membership_level->billing_limit ) ) {
			$subscription_phase->setPeriods( absint( $membership_level->billing_limit ) );
		}

		$phases[] = $subscription_phase;

		return $phases;

	}

	/**
	 * Creating a new subscription plan variation.
	 **/
	private function create_subscription_plan_variation( $subscription_plan_id, $membership_level, $order ) {
		global $pmpro_currency;

		$this->log( 'Starting create_subscription_plan_variation...' );

		// Get the phases based on membership level settings.
		$phases = $this->create_subscription_phases( $membership_level, $order );

		$subscription_plan_variation_data = new \Square\Models\CatalogSubscriptionPlanVariation( $membership_level->name, $phases );
		$subscription_plan_variation_data->setSubscriptionPlanId( $subscription_plan_id );
		$subscription_plan_variation_data->setName( $membership_level->name );

		$object = new \Square\Models\CatalogObject( 'SUBSCRIPTION_PLAN_VARIATION', '#1' );
		$object->setSubscriptionPlanVariationData( $subscription_plan_variation_data );

		$body = new \Square\Models\UpsertCatalogObjectRequest( $this->get_idempotency_key(), $object );

		$api_response = $this->client->getCatalogApi()->upsertCatalogObject( $body );

		if ( $api_response->isSuccess() ) {
			$catalog_object = $api_response->getResult()->getCatalogObject();
			$subscription_plan_variation_id = $catalog_object->getId();
			$this->log( 'Created new subscription plan variation: ' . $subscription_plan_variation_id );

			// Get existing plan variations and add this new one to it.
			$existing_plan_variations = get_option( 'pmpro_square_subscription_plan_variations_' . $this->get_environment() . '_' . $membership_level->id );
			if ( empty( $existing_plan_variations ) ) {
				$existing_plan_variations = array();
			}
			$existing_plan_variations[ $subscription_plan_variation_id ] = (array) $membership_level;
			update_option( 'pmpro_square_subscription_plan_variations_' . $this->get_environment() . '_' . $membership_level->id, $existing_plan_variations );
			return $subscription_plan_variation_id;
		} 

		$this->log( 'Failed to create subscription plan variation via API' );

		return false;

	}

	/**
	 * Creates a customer in Square.
	 */
	public function create_customer( $user ) {
		$customer_request = new \Square\Models\CreateCustomerRequest();
		$customer_request->setGivenName( $user->first_name );
		$customer_request->setFamilyName( $user->last_name );
		$customer_request->setEmailAddress( $user->user_email );
		$customer_response = $this->client->getCustomersApi()->createCustomer( $customer_request );
		if ( $customer_response->isSuccess()) {
			$square_customer = $customer_response->getResult()->getCustomer();
			$square_customer_id = $square_customer->getId();
			$this->log( 'Customer created in Square' );
			update_user_meta( $user->ID, 'pmpro_square_customer_id_' . $this->get_environment(), $square_customer_id );
			return $square_customer_id;
		} else {
			$errors = $customer_response->getErrors();
			$this->log( $errors, 'Customer NOT created' );
			return false;
		}
	}

	/**
	 * Process checkout.
	 */
	public function process( &$order ) {
		global $pmpro_currency, $current_user;

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		$this->setup();

		$this->log( 'Starting new order with Square...' );

		// clean up a couple values
		$order->payment_type = 'Square';
		$order->CardType     = '';
		$order->cardtype     = '';
		$order->status = 'token';

		// Bail if we did not get the payment token generated by JS on the frontend.
		if ( empty( $_POST['square_payment_token'] ) ) {
			return false;
		}

		$square_token = sanitize_text_field( $_POST['square_payment_token'] );

		// Create the customer in Square if not currently set.
		$square_customer_id = '';
		if ( ! empty( $order->user_id ) ) {
			$user_id = $order->user_id;
		}
		if ( empty( $user_id ) && ! empty( $current_user->ID ) ) {
			$user_id = $current_user->ID;
			$user = $current_user;
		}
		if ( ! empty( $user_id ) ) {
			$user = empty( $user_id ) ? null : get_userdata( $user_id );

			// Create new customer if does not exist.
			$square_customer_id = get_user_meta( $user_id, 'pmpro_square_customer_id_' . $this->get_environment(), true );
			if ( ! $square_customer_id ) {
				$square_customer_id = $this->create_customer( $user );
			} else {
				// Look for existing customer in Square to confirm it exists.
				$api_response = $this->client->getCustomersApi()->retrieveCustomer( $square_customer_id );

				if ( $api_response->isSuccess() ) {
					$result = $api_response->getResult();
					$customer = $result->getCustomer();
					// If our customer IDs are different, let's save the found one to usermeta.
					// This could happen if someone changed to a new Square account and the customer existing in the new one but meta was still saved as old one.
					// Not at all likely, but ya never know - people be doing crazy things.
					if ( $square_customer_id != $customer->getId() ) {
						$square_customer_id = $customer->getId();
						update_user_meta( $user_id, 'pmpro_square_customer_id_' . $this->get_environment(), $square_customer_id );
					}
				} else {
					$errors = $api_response->getErrors();
					$this->log( 'Could not find existing customer (' . $square_customer_id . '), creating a new one...' );
					$square_customer_id = $this->create_customer( $user );
					if ( empty( $square_customer_id ) ) {
						$this->log( 'Could not create a new customer in Square' );
						return false; // We must have a customer so let's just bail.
					}
				}
			}

		} else {
			// No user account was available, bail.
			$this->log( 'No user account was available when processing order' );
			return false;
		}

		// Setup billing address for API request if present.
		if ( ! empty( $order->billing->name ) ) {
			$this->log( 'Prepping billing address' );
			$address = new \Square\Models\Address();
			$address->setAddressLine1( $order->billing->street );
			$address->setAddressLine2( $order->billing->street2 );
			$address->setLocality( $order->billing->city );
			$address->setAdministrativeDistrictLevel1( $order->billing->state );
			$address->setPostalCode( $order->billing->zip );
			$address->setCountry( $order->billing->country );
			// Pull from POST data as they are separate whereas order combines into single name.
			if ( ! empty( $_POST['bfirstname'] ) ) {
				$address->setFirstName( sanitize_text_field( $_POST['bfirstname'] ) );
			}
			if ( ! empty( $_POST['blastname'] ) ) {
				$address->setLastName( sanitize_text_field( $_POST['blastname'] ) );
			}
		}

		// One-time payments.
		if ( ! pmpro_isLevelRecurring( $order->membership_level ) ) {

			$this->log( $order, 'Process one time payment...' );

			$initial_subtotal       = $order->subtotal;
			$initial_tax            = $order->getTaxForPrice( $initial_subtotal );
			$initial_payment_amount = pmpro_round_price( (float) $initial_subtotal + (float) $initial_tax );
			$initial_payment = $initial_payment * 100; // Square works in cents.

			$amount_money = new \Square\Models\Money();
			$amount_money->setAmount( $initial_payment_amount );
			$amount_money->setCurrency( $pmpro_currency );

			$body = new \Square\Models\CreatePaymentRequest( $square_token, $this->get_idempotency_key() );
			$body->setAmountMoney( $amount_money );
			$body->setCustomerId( $square_customer_id );
			$body->setLocationId( $this->location_id );
			$body->setReferenceId( $order->code );
			$body->setAcceptPartialAuthorization( false );
			$body->setBuyerEmailAddress( $user->user_email );

			if ( ! empty( $address ) ) {
				$body->setBillingAddress( $address );
			}

			$api_response = $this->client->getPaymentsApi()->createPayment( $body );

			if ( $api_response->isSuccess() ) {
				$result = $api_response->getResult();
				$payment = $api_response->getResult()->getPayment();
				$order->payment_transaction_id = $payment->getId();
				$order->status = 'success';
				$this->log( 'One time payment succeeded' );
				return true;
			} else {
				$errors = $api_response->getErrors();
				foreach ( $errors as $error ) {
					$order->error      .= __( 'Error processing payment', 'pmpro-square' ) . ': ' . $error->getCode();
					$order->shorterror = $error->getCode();
				}
				$this->log( $order->error );
				return false;
			}

		} else {

			// Otherwise, it is a subscription and need to set that up.
			$this->log( 'Processing subscription...' );

			/******************
			Get the current Square Subscription Plan ID for this Membership Level.
			******************/
			$square_subscription_plan_id = get_option( 'pmpro_square_subscription_plan_id_' . $this->get_environment() . '_' . $order->membership_level->id );
			if ( empty( $square_subscription_plan_id ) ) {

				$this->log( 'No Square Subscription Plan for this Membership Level' );

				// Create a new subscription plan for the membership level since it does not yet exist.
				$square_subscription_plan_id = $this->create_subscription_plan( $order->membership_level );
				if ( ! $square_subscription_plan_id ) {
					$this->log( 'Failed to create subscription plan in Square' );
					wp_die( esc_html__( 'Failed to create subscription plan in Square', 'pmpro-square' ) );
					exit;
				}

			}

			/******************
			Get the current Square Subscription Plan Variation ID for this Membership Level.
			******************/
			$square_subscription_plan_variation_id = $this->get_subscription_plan_variation_id( $order->membership_level, $order );
			if ( ! $square_subscription_plan_variation_id ) {

				$this->log( 'Since no subscription plan variation id found, try creating a new one...' );

				// Create a new subscription plan variation for the membership level since it does not yet exist or is different from the past saved ones.
				$square_subscription_plan_variation_id = $this->create_subscription_plan_variation( $square_subscription_plan_id, $order->membership_level, $order );
				if ( ! $square_subscription_plan_variation_id ) {
					$this->log( 'Failed at creating subscription plan variation in square' );
					wp_die( esc_html__( 'Failed to create subscription plan variation in Square', 'pmpro-square' ) );
					exit;
				}

			}

			/******************
			Add the square token to the card on file for this customer.
			******************/
			$card = new \Square\Models\Card();
			$card->setCustomerId( $square_customer_id );
			if ( ! empty( $order->billing->street ) ) {
				if ( ! empty( $order->billing->name ) ) {
					$card->setCardholderName( $order->billing->name );
				}
				$card->setBillingAddress( $address );
			}

			$body = new \Square\Models\CreateCardRequest(
				$this->get_idempotency_key(),
				$square_token,
				$card
			);

			$api_response = $this->client->getCardsApi()->createCard( $body );

			if ( $api_response->isSuccess() ) {
				$this->log( 'Card added to file of customer' );
				$result = $api_response->getResult();
				$card = $result->getCard();
				/* Not using, so don't save but leaving in case we change our mind later
				// Update customer profile to save card info to meta.
				$card_data = array(
					'id' => $card->getId(),
					'last4' => $card->getLast4(),
					'exp_month' => $card->getExpMonth(),
					'exp_year' => $card->getExpYear(),
					'brand' => $card->getCardBrand(),
				);
				$cards = get_user_meta( $user_id, 'pmpro_square_cards', true );
				if ( empty( $cards ) ) {
					$cards = array();
				}
				$cards[] = $card_data;
				update_user_meta( $user->ID, 'pmpro_square_cards', $cards );
				*/
			} else {
				$errors = $api_response->getErrors();
				foreach ( $errors as $error ) {
					$order->error      .= __( 'Error processing payment', 'pmpro-square' ) . ': ' . $error->getCode();
					$order->shorterror = $error->getCode();
				}
				$this->log( $order->error );
				return false; // Card MUST be saved on file, only way to set up subs in Square.
			}

			/******************
			Put all the pieces together to make the final subscription request.
			******************/
			$body = new \Square\Models\CreateSubscriptionRequest( $this->location_id, $square_customer_id );
			$body->setIdempotencyKey( $this->get_idempotency_key() );
			$body->setPlanVariationId( $square_subscription_plan_variation_id );
			$body->setCardId( $card->getId() );

			// If we have any tax applied, assume it matches the one available tax rate and pass along.
			if ( $order->tax > 0 ) {
				$tax_rate = get_option( "pmpro_tax_rate" );
				$this->log( 'Applying tax rate: ' . ( $tax_rate * 100 ) );
				$body->setTaxPercentage( $tax_rate * 100 );
			}

			// If no initial payment, then there is a free trial and need to set a future start date.
			// But need to take the subscription delay into consideration as well.
			$subscription_delay  = get_option( 'pmpro_subscription_delay_' . $order->membership_level->id , '' );
			if ( empty( $order->subtotal ) && empty( $subscription_delay ) ) {
				$start_date = date( 'Y-m-d', strtotime( '+1 ' . $order->membership_level->cycle_period ) );
				$body->setStartDate( $start_date );
				$this->log( 'Setting delayed start date for trial period: ' . $start_date );
			} elseif ( empty( $order->subtotal ) && ! empty( $subscription_delay ) ) {
				// No initial payment but there is a subscription delay, so lets set it based on that.
				$subscription_start_date = pmpro_calculate_profile_start_date( $order, 'Y-m-d' );
				$body->setStartDate( $subscription_start_date );
				$this->log( 'Setting delayed start date for subscription delay and no initial payment: ' . $subscription_start_date );
			}

			$api_response = $this->client->getSubscriptionsApi()->createSubscription( $body );
			if ( $api_response->isSuccess() ) {
				$result = $api_response->getResult();
				$subscription = $result->getSubscription();
				$this->log( 'Subscription created' );
				$order->subscription_transaction_id = $subscription->getId();
				// We need to set the payment ID in the webhook since the API does not return any data on invoices/payments yet.
				// Square can take a few minutes to actually process the first payment.			
				$order->status = 'success';
				return true;
			} else {
				$this->log( $api_response, 'Subscription creation failed' );
				$errors = $api_response->getErrors();
				$order->error      .= __( 'Could not establish customer subscription in Square', 'pmpro-square' );
				$order->shorterror = $errors[0]->getCode();
				return false;
			}
				
		}

		// If we got here, something didn't go correctly.
		return false;

	}

	/**
	 * Clear $pmpro_review.
	 *
	 * Do not show the Complete Payment screen if there is an error.
	 */
	public static function clear_pmpro_review( $pmpro_review ) {
		// If we don't have an order, bail.
		if ( empty( $pmpro_review ) || ! is_a( $pmpro_review, 'MemberOrder' ) ) {
			return;
		}

		// If this is not a Square order, bail.
		if ( 'square' !== $pmpro_review->gateway ) {
			return;
		}

		// Clear the global.
		global $pmpro_review;
		$pmpro_review = false;
	}

	/**
	 * Updating the card on file for the subscription.
	 */
	public function update_billing_card( $user_id, $order ) {
		global $pmpro_msg, $pmpro_msgt;

		$this->log( 'Attempting to update billing card...' );

		$this->setup();

		$square_customer_id = get_user_meta( $user_id, 'pmpro_square_customer_id_' . $this->get_environment(), true );
		if ( empty( $square_customer_id ) ) {
			$this->log( 'No customer found in Square' );
			$pmpro_msg = __( 'No customer found in Square', 'pmpro-square' );
			$pmpro_msgt = "pmpro_error";
			return;
		}

		if ( empty( $order->subscription_transaction_id ) ) {
			$this->log( 'No Square subscription found' );
			$pmpro_msg = __( 'No Square subscription found', 'pmpro-square' );
			$pmpro_msgt = "pmpro_error";
			return;
		}

		$square_token = sanitize_text_field( $_POST['square_payment_token'] );
		if ( empty( $square_token ) ) {
			$this->log( 'No Square payment info found' );
			$pmpro_msg = __( 'No Square payment info found', 'pmpro-square' );
			$pmpro_msgt = "pmpro_error";
			return;
		}
		
		// Set billing address if it exists.
		if ( ! empty( $order->billing->street ) ) {
			$address = new \Square\Models\Address();
			$address->setAddressLine1( $order->billing->street );
			$address->setAddressLine2( $order->billing->street2 );
			$address->setLocality( $order->billing->city );
			$address->setAdministrativeDistrictLevel1( $order->billing->state );
			$address->setPostalCode( $order->billing->zip );
			$address->setCountry( $order->billing->country );
			// Pull from POST data as they are separate whereas order combines into single name.
			if ( ! empty( $_POST['bfirstname'] ) ) {
				$address->setFirstName( sanitize_text_field( $_POST['bfirstname'] ) );
			}
			if ( ! empty( $_POST['blastname'] ) ) {
				$address->setLastName( sanitize_text_field( $_POST['blastname'] ) );
			}
		}

		// Add the new card from the POSTed token.
		$card = new \Square\Models\Card();
		$card->setCustomerId( $square_customer_id );
		if ( ! empty( $order->billing->street ) ) {
			$card->setCardholderName( $order->billing->name );
			$card->setBillingAddress( $address );
		}

		$body = new \Square\Models\CreateCardRequest(
			$this->get_idempotency_key(),
			$square_token,
			$card
		);

		$api_response = $this->client->getCardsApi()->createCard( $body );

		if ( $api_response->isSuccess() ) {
			$this->log( 'Card added to file of customer' );
			$result = $api_response->getResult();
			$card = $result->getCard();
			/* Not using, so don't save but leaving in case we change our mind later
			// Update customer profile to save card info to meta.
			$card_data = array(
				'id' => $card->getId(),
				'last4' => $card->getLast4(),
				'exp_month' => $card->getExpMonth(),
				'exp_year' => $card->getExpYear(),
				'brand' => $card->getCardBrand(),
			);
			$cards = get_user_meta( $user_id, 'pmpro_square_cards', true );
			if ( empty( $cards ) ) {
				$cards = array();
			}
			$cards[] = $card_data;
			update_user_meta( $user_id, 'pmpro_square_cards', $cards );
			*/
		} else {
			$this->log( $errors, 'Errors updating billing information' );
			$errors = $api_response->getErrors();
			$pmpro_msg = __( 'Could not update billing card', 'pmpro-square' );
			$pmpro_msgt = "pmpro_error";
			return false; // Card MUST be saved on file, only way to set up subs in Square.
		}

		// Apply the new card to the subscription.
		$subscription = new \Square\Models\Subscription();
		$subscription->setCardId( $card->getId() );

		$body = new \Square\Models\UpdateSubscriptionRequest();
		$body->setSubscription( $subscription );

		$api_response = $this->client->getSubscriptionsApi()->updateSubscription( $order->subscription_transaction_id, $body );

		if ( $api_response->isSuccess() ) {
			$this->log( 'Card applied to subscription' );
		} else {
			$errors = $api_response->getErrors();
			$this->log( $errors, 'Errors updating billing information' );
			$pmpro_msg = __( 'Could not update billing card', 'pmpro-square' );
			$pmpro_msgt = "pmpro_error";
			return false; // Card MUST be saved on file, only way to set up subs in Square.
		}

	}

	/**
	 * Cancelling the subscription at Square.
	 */
	public function cancel_subscription( $subscription ) {

		$this->log( 'Attempting to cancel subscription...' );

		$this->setup();

		$subscription_id = $subscription->get_subscription_transaction_id();
		$api_response = $this->client->getSubscriptionsApi()->cancelSubscription( $subscription_id );

		if ( $api_response->isSuccess() ) {
			$this->log( 'Subscription cancelled at Square via API: ' . $subscription_id );
			return true;
		}

		$this->log( 'Subscription failed to cancel at Square via API: ' . $subscription_id );
		$this->log( $api_response );
		return false;

	}

	public function update_subscription_info( $subscription ) {

		$this->log( 'Syncing subscription...' );

		$subscription_id = $subscription->get_subscription_transaction_id();

		$this->setup();

		$api_response = $this->client->getSubscriptionsApi()->retrieveSubscription( $subscription_id );

		if ($api_response->isSuccess()) {
			$square_subscription = $api_response->getResult()->getSubscription();

			$status = $square_subscription->getStatus();
			if ( $status === 'ACTIVE' ) {
				$update_array['status'] = 'active';
			} elseif ( $status === 'CANCELED' ) {
				$update_array['status'] = 'cancelled';
			} else {
				// If still in any other status we can ignore.
				return false;
			}

			// We get this cancelled date separate from status because it could be canceled but still running until the expiration date.
			$cancelled_date = $square_subscription->getCanceledDate();
			if ( ! empty( $cancelled_date ) ) {
				$update_array['status'] = 'cancelled';
				$update_array['enddate'] = date( 'Y-m-d H:i:s', strtotime( $cancelled_date ) );
				$update_array['next_payment_date'] = '';
			} else {
				$charged_through_date = $square_subscription->getChargedThroughDate();
				if ( $charged_through_date ) {
					// Square charged through date does not include the time so we are appending the time from the subs original startdate.
					$update_array['next_payment_date'] = date( 'Y-m-d H:i:s', strtotime( $charged_through_date . ' ' . $subscription->get_startdate( 'H:i:s' ) ) );
				}
			}

			$plan_variation_id = $square_subscription->getPlanVariationId();
			$api_response = $this->client->getCatalogApi()->retrieveCatalogObject( $plan_variation_id );
			if ($api_response->isSuccess()) {
				$result = $api_response->getResult();

				// Have to cascade down through the objects to get what we need: phases.
				$catalog_object = $result->getObject();
				$plan_variation_data = $catalog_object->getSubscriptionPlanVariationData();
				$phases = $plan_variation_data->getPhases();

				// Use the last phase to determine the cycle period and price as this is what is used to set this info by default during checkout.
				$last_phase = array_pop( $phases );

				$cadence = $last_phase->getCadence();
				if ( $cadence == "DAILY" ) {
					$update_array['cycle_period'] = 'Day';
				} else if ( $cadence == "WEEKLY" ) {
					$update_array['cycle_period'] = 'Week';
				} else if ( $cadence == "MONTHLY" ) {
					$update_array['cycle_period'] = 'Month';
				} else if ( $cadence == "ANNUAL" ) {
					$update_array['cycle_period'] = 'Year';
				}

				$update_array['billing_amount'] = (float) $last_phase->getPricing()->getPriceMoney()->getAmount() / 100;
		
			} else {
				$errors = $api_response->getErrors();
				$this->log( 'Failed syncing subscription - getting plan variation: ' . print_r( $errors, 1 ) );
				return print_r( $errors, 1 );
			}

			$this->log( 'Subscription synced' );
			$this->log( $update_array );
			$subscription->set( $update_array );
	
		} else {
			$errors = $api_response->getErrors();
			$this->log( 'Failed syncing subscription: ' . print_r( $errors, 1 ) );
			return print_r( $errors, 1 );
		}
		return false;
	}

	/**
	 * Send traffic from wp-admin/admin-ajax.php?action=pmpro_square_webhook to webhook handler
	 */
	static function webhook_listener() {
		require_once PMPRO_SQUARE_DIR . 'services/square_webhook.php';
		exit;
	}

	/**
	 * Write to our custom log file.
	 */
	private function log( $message, $prefix = '' ) {

		if ( get_option( "pmpro_square_log" ) !== 'yes' || empty( $this->log_file ) ) {
			return;
		}

		$date = '[' . current_time( 'y-m-d H:i:s' ) . ']';

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true );
		}

		$log_message = $date . ' ' . $message;

		if ( $prefix ) {
			if ( is_array( $prefix ) || is_object( $prefix ) ) {
				$prefix = print_r( $prefix, true );
			}
			$log_message = $date . ' ' . $prefix . "\n" . $log_message;
		}

		$pattern = '/(?<=@)([a-zA-Z0-9._%+-]+)(?=\.[a-zA-Z]{2,})/';
		$log_message = preg_replace( $pattern, '****', $log_message );

		if ( is_user_logged_in() ) {
			$log_message .= ' (User ID: ' . get_current_user_id() . ')';
		}
		$log_message .= "\n";

		$fp = fopen( $this->log_file, 'a' );
		fwrite( $fp, $log_message );
		fclose( $fp );

	}

	/**
	 * Get the file path for the debug log file.
	 *
	 * @return string
	 */
	function get_log_file_path() {
		// Check if we have a unique file name saved already.
		$file_name = get_option( 'pmpro_square_log_file_name' );
		if ( empty( $file_name ) ) {
			$file_name = 'pmpro-square-log-' . uniqid() . '.txt';
			update_option( 'pmpro_square_log_file_name', $file_name );
		}

		// Build the debug log file path in uploads dir
		$wp_upload_dir  = wp_upload_dir();
		$log_path = $wp_upload_dir['basedir'] . '/' . $file_name;

		/**
		 * Filter the debug log file path. 
		 * 
		 * @param string $path
		 */
		$log_path = apply_filters( 'pmpro_square_log_path', $log_path );

		return $log_path;
	}

}
