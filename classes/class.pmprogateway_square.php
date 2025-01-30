<?php
use Square\SquareClientBuilder;
use Square\Authentication\BearerAuthCredentialsBuilder;
use Square\Environment;

//load classes init method
add_action( 'init', function(){
	new PMProGateway_Square();
} );
//add_filter( 'pmpro_is_ready', array( $this, 'is_ready' ), 999, 1 );

class PMProGateway_Square extends PMProGateway {

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

		$this->init();

		return $this->gateway;

	}

	/**
	 * Run on WP init
	 *
	 * @since 1.8
	 */
	private function init() {
		
		//make sure Square is a gateway option
		add_filter( 'pmpro_gateways', array( $this, 'pmpro_gateways' ));
		add_filter( 'pmpro_gateways_with_pending_status', array( $this, 'pmpro_gateways_with_pending_status' ) );

		//add fields to payment settings
		add_filter( 'pmpro_payment_options', array( $this, 'pmpro_payment_options' ));
		add_filter( 'pmpro_payment_option_fields', array( $this, 'pmpro_payment_option_fields' ), 10, 2);
		//code to add at checkout
		$gateway = pmpro_getGateway();

		if ( $gateway == "square" ) {		

			$this->setup();

			// Enqueue Square JS on checkout page.
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

			add_filter( 'pmpro_required_billing_fields', array( $this, 'required_billing_fields' ) );
			//add_filter( 'pmpro_checkout_default_submit_button', array( $this, 'pmpro_checkout_default_submit_button' ) );
			// add_filter( 'pmpro_checkout_before_change_membership_level', array( $this, 'pmpro_checkout_before_change_membership_level' ), 10, 2);
			//add_action( 'pmpro_after_saved_payment_options', array( $this, 'pmpro_square_create_default_subscription_plan' ) );
			add_action( 'pmpro_after_saved_payment_options', array( $this, 'refresh_locations_auto' ) );
			add_action( 'pmpro_after_saved_payment_options', array( $this, 'create_webhooks_auto' ) );
			//add_action( 'admin_notices', array( $this, 'pmpro_square_create_default_subscription_plan_manual' ) );
			add_action( 'admin_notices', array( $this, 'refresh_locations_manual' ) );
			add_action( 'admin_notices', array( $this, 'create_webhooks_manual' ) );

			add_filter( 'pmpro_include_billing_address_fields', array( $this, 'include_billing_address_fields' ) );
			//add_filter( 'pmpro_include_payment_information_fields', '__return_false');
			add_filter( 'pmpro_include_payment_information_fields', array( $this, 'include_payment_information_fields' ) );	

			add_action( 'wp_ajax_nopriv_pmpro_square_init_order', array( $this, 'init_order' ) );
			add_action( 'wp_ajax_pmpro_square_init_order', array( $this, 'init_order' ) );

			add_action( 'wp', array( $this, 'webhook_listener' ), 999 );

			$wp_upload_dir  = wp_upload_dir();
			$this->log_file = $wp_upload_dir['basedir'] . '/pmpro-square.log';
	
		}
	}

	/*
	public function pmpro_gateways_with_pending_status( $gateways ) {
		$gateways[] = 'square';
		return $gateways;
	}
	*/

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

	private function setup() {

		if ( ! empty( $this->client ) ) {
			return; // Don't run again.
		}

		$environment = get_option( 'pmpro_gateway_environment' );
		if ( $environment == 'live' ) {
			$this->gateway_environment = 'live';
			$this->base_url = 'https://connect.squareup.com';
			$this->application_id = get_option( 'pmpro_square_live_application_id' );
			$this->location_id = $this->get_location_id( 'live' );
			$this->personal_access_token = get_option( 'pmpro_square_live_personal_access_token' );
			//$this->subscription_plan_id = get_option( 'pmpro_square_live_subscription_plan_id' );
		} else {
			$this->gateway_environment = 'sandbox';
			$this->base_url = 'https://connect.squareupsandbox.com';
			$this->application_id = get_option( 'pmpro_square_sandbox_application_id' );
			$this->location_id = $this->get_location_id( 'sandbox' );
			$this->personal_access_token = get_option( 'pmpro_square_sandbox_personal_access_token' );
			//$this->subscription_plan_id = get_option( 'pmpro_square_sandbox_subscription_plan_id' );
		}
		if ( empty( $this->personal_access_token ) ) {
			return; // Don't proceed, we don't have the proper credentials.
		}
		$this->client = SquareClientBuilder::init()
		->bearerAuthCredentials(
			BearerAuthCredentialsBuilder::init( $this->personal_access_token )
		)
		->environment( ( $environment == 'live' ) ? Environment::PRODUCTION : Environment::SANDBOX )
		->squareVersion( '2024-12-18' )
		->build();
		
	}

	/**
	 * Check if all fields are complete
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

	/*
	We do not need this anymore, we are creating one subscription plan per membership level instead.
	private function create_default_subscription_plan_manual() {

		if ( empty( $_GET['pmpro_square_create_default_subscription'] ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_create_default_subscription' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_create_default_subscription', 'GET' );
		$result = $this->create_default_subscription_plan( $environment );
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

	private function create_default_subscription_plan_auto() {

		$environment = pmpro_getOption( 'gateway_environment' );
		$subscription_plan_id = pmpro_getOption( 'square_' . $environment . '_subscription_plan_id' );

		if ( empty( $subscription_plan_id ) && $this->is_ready() ) {
			$this->create_default_subscription_plan( $environment );
		}

	}
		*/

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

		$api_response = $this->client->getCatalogApi()->upsertCatalogObject( $body );

		if ( $api_response->isSuccess() ) {
			$catalog_object = $api_response->getResult()->getCatalogObject();
			pmpro_setOption( 'square_subscription_plan_id_' . $this->gateway_environment . '_' . $membership_level->id, $catalog_object->getId() );
			$this->log( 'Created new subscription plan: ' . $catalog_object->getId() );
			return $catalog_object->getId();
		} else {
			$this->log( $api_response, 'FAILED TO CREATE SUBSCRIPTION PLAN' );
			return false;
		}

	}

	private function create_subscription_phases( $membership_level, $order ) {
		global $pmpro_currency;

		$this->log( 'Starting create_subscription_phases...' );

		$phases = array();

		$subscription_delay  = get_option( 'pmpro_subscription_delay_' . $order->membership_level->id , '' );

		// Figure out cadence based on cycle period.
		if ( $membership_level->cycle_period == "Day" ) {
			$cycle_period = 'DAILY';
			//$subscription_phase->setCadence( 'DAILY' );
		} else if ( $membership_level->cycle_period == "Week" ) {
			$cycle_period = 'WEEKLY';
			//$subscription_phase->setCadence( 'WEEKLY' );
		} else if ( $membership_level->cycle_period == "Month" ) {
			$cycle_period = 'MONTHLY';
			//$subscription_phase->setCadence( 'MONTHLY' );
		} else if ( $membership_level->cycle_period == "Year" ) {
			$cycle_period = 'ANNUAL';
			//$subscription_phase->setCadence( 'ANNUAL' );
		}

		$ordinal = 0;
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

		if ( ! empty( $membership_level->billing_limit ) ) {
			$subscription_phase->setPeriods( absint( $billing_limit ) );
		}

		$phases[] = $subscription_phase;

		$this->log( $phases, 'Phases' );

		return $phases;

	}

	private function create_subscription_plan_variation( $subscription_plan_id, $membership_level, $order ) {
		global $pmpro_currency;

		$this->log( 'Starting create_subscription_plan_variation...' );

		$phases = $this->create_subscription_phases( $membership_level, $order );

		$subscription_plan_variation_data = new \Square\Models\CatalogSubscriptionPlanVariation( $membership_level->name, $phases );
		$subscription_plan_variation_data->setSubscriptionPlanId( $subscription_plan_id );
		$subscription_plan_variation_data->setName( $membership_level->name );

		$object = new \Square\Models\CatalogObject( 'SUBSCRIPTION_PLAN_VARIATION', '#1' );
		$object->setSubscriptionPlanVariationData( $subscription_plan_variation_data );

		// Creating a new plan variation.
		// Ideally we don't create a new one every time but doing this for now.
		$body = new \Square\Models\UpsertCatalogObjectRequest( $this->get_idempotency_key(), $object );

		$api_response = $this->client->getCatalogApi()->upsertCatalogObject( $body );

		if ( $api_response->isSuccess() ) {
			$catalog_object = $api_response->getResult()->getCatalogObject();
			$subscription_plan_variation_id = $catalog_object->getId();
			$this->log( 'Created new subscription plan variation: ' . $subscription_plan_variation_id );

			// Get existing plan variations and add this new one to it.
			$existing_plan_variations = get_option( 'pmpro_square_subscription_plan_variations_' . $this->gateway_environment . '_' . $membership_level->id );
			if ( empty( $existing_plan_variations ) ) {
				$existing_plan_variations = array();
			}
			$existing_plan_variations[ $subscription_plan_variation_id ] = (array) $membership_level;
			update_option( 'pmpro_square_subscription_plan_variations_' . $this->gateway_environment . '_' . $membership_level->id, $existing_plan_variations );
			return $subscription_plan_variation_id;
		} 

		$this->log( 'Failed to create subscription plan variation via API' );

		return false;

	}

	public function refresh_locations_manual() {

		if ( empty( $_GET['pmpro_square_refresh_locations'] ) ) {
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
				<p><?php esc_html_e( 'Locations could not be refreshed', 'pmpro-square' ); ?>: <?php echo esc_html_e( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}

	public function refresh_locations_auto() {

		$existing_locations = pmpro_getOption( 'square_locations_' . $this->gateway_environment );

		if ( empty( $existing_locations ) && $this->is_ready() ) {
			$this->refresh_locations( $this->gateway_environment );
		}

	}

	private function refresh_locations( $environment ) {

		if ( $this->is_ready() ) {
			$this->log( 'refreshing locations' );
			$this->setup();
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

	private function get_webhook_url() {
		$url = trailingslashit( get_bloginfo( 'url' ) );
		$url = add_query_arg( 'pmpro_square_webhook', 1, $url );
		return $url;
	}

	public function create_webhooks_manual() {

		if ( empty( $_GET['pmpro_square_webhooks'] ) ) {
			return false;
		}

		if ( ! empty( $_GET['_wpnonce'] ) && ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_webhooks' ) ) {
			return false;
		}

		$environment = pmpro_getParam( 'pmpro_square_webhooks', 'GET' );
		$result = $this->create_webhooks( $environment, true );
		if ( ! empty( $result['success'] ) ) {
			?>
			<div class="updated notice">
				<p><?php esc_html_e( 'Webhooks have been created', 'pmpro-square' ); ?></p>
			</div>
			<?php
		} else {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'Webhooks could not be created', 'pmpro-square' ); ?>: <?php esc_html_e( $result['error'] ); ?></p>
			</div>
			<?php	
		}
	}

	public function create_webhooks_auto() {

		$existing_webhooks = pmpro_getOption( 'square_webhook_' . $this->gateway_environment );

		if ( empty( $existing_webhooks ) && $this->is_ready() ) {
			$this->create_webhooks( $this->gateway_environment );
		}

	}

	/*
	 * Generate the webhooks automatically
	 * Uses Personal Access Token so webhooks can be associated with customer app and not PMPro app
	 * This requires a separate cURL request using the personal access token instead
	 */
	private function create_webhooks( $environment, $force = false ) {

		$webhooks = pmpro_getOption( 'square_webhook_' . $environment );
		if ( ( ! $webhooks || $force ) && $this->is_ready() ) {

			$this->setup();

			$this->log( 'Creating webhooks in ' . $environment . '...' );

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
					$this->log( $response_body, "Square webhook subscription created successfully" );
					update_option( 'pmpro_square_webhook_' . $environment, $response_body['subscription'] );
					return array( 'success' => true );
				} else {
					$this->log( $response_body );
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
	public function pmpro_payment_option_fields( $values, $gateway ) {
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
		<!--
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row">
				<label><?php esc_html_e( 'Subscriptions Status', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<?php if ( ! pmpro_getOption( 'square_sandbox_subscription_plan_id' ) ) { ?>
					<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_create_default_subscription=sandbox' ), 'pmpro_square_create_default_subscription' ); ?>" class="button"><?php esc_html_e( 'Subscription Setup', 'pmpro-square' );?></a>
				<?php } else { ?>
					<div class="notice notice-success inline">
						<p><?php esc_html_e( 'Subscription capabilities enabled', 'pmpro-square' ); ?> - <?php echo pmpro_getOption( 'square_sandbox_subscription_plan_id' ); ?></p>
					</div>
				<?php } ?>
			</td>
		</tr>
				-->
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
		<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row">
				<label for="square_sandbox_webhooks"><?php esc_html_e( 'Sandbox Webhooks', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<?php
				$webhook = get_option( 'pmpro_square_webhook_sandbox' );
				if ( $webhook ) {
					echo '<div class="notice notice-success inline"><p>' . join( ', ', $webhook['event_types'] ) . '</p></div>';
					echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=sandbox' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Refresh webhooks', 'pmpro-square' ) . '</a>';
				} else {
					echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=sandbox' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a>';
				}
				?>
			</td>
		</tr>
	<?php } ?>

	<tr class="gateway gateway_square gateway_square_sandbox" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="square_live_application_id"><?php esc_html_e( 'Live Application ID', 'pmpro-square' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="square_live_application_id" name="square_live_application_id" size="60" value="<?php echo esc_attr( $values['square_live_application_id'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Application ID from Square', 'pmpro-square' );?></small>
		</td>
	</tr>
	<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="square_live_personal_access_token"><?php esc_html_e( 'Live Access Token', 'pmpro-square' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="square_live_personal_access_token" name="square_live_personal_access_token" size="60" value="<?php echo esc_attr( $values['square_live_personal_access_token'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the access token ID from Square', 'pmpro-square' );?></small>
		</td>
	</tr>

	<?php if ( ! empty( $values['square_live_personal_access_token'] ) ) { ?>
		<!--
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'sandbox' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row">
				<label><?php esc_html_e( 'Subscriptions Status', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
				<?php if ( ! pmpro_getOption( 'square_live_subscription_plan_id' ) ) { ?>
					<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_create_default_subscription=live' ), 'pmpro_square_create_default_subscription' ); ?>" class="button"><?php esc_html_e( 'Subscription Setup', 'pmpro-square' );?></a>
				<?php } else { ?>
					<div class="notice notice-success inline">
						<p><?php esc_html_e( 'Subscription capabilities enabled', 'pmpro-square' ); ?> - <?php echo pmpro_getOption( 'square_live_subscription_plan_id' ); ?></p>
					</div>
				<?php } ?>
			</td>
		</tr>
				-->
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
				<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=live' ), 'pmpro_square_refresh_locations' ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' );?></a>
			</td>
		</tr>
		<tr class="gateway gateway_square gateway_square_live" <?php if ( $gateway != "square" || $values['gateway_environment'] != 'live' ) { ?>style="display: none;"<?php } ?> >
			<th scope="row" valign="top">
				<label for="square_live_webhooks"><?php esc_html_e( 'Live Webhooks', 'pmpro-square' ); ?>:</label>
			</th>
			<td>
			<?php
				$webhook = get_option( 'pmpro_square_webhook_live' );
				if ( $webhook ) {
					echo '<div class="notice notice-success inline"><p>' . join( ', ', $webhook['event_types'] ) . '</p></div>';
					echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=live' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Refresh webhooks', 'pmpro-square' ) . '</a>';
				} else {
					echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=live' ), 'pmpro_square_webhooks' ) . '" class="button">' . esc_html__( 'Generate webhooks', 'pmpro-square' ) . '</a>';
				}
				?>
			</td>
		</tr>
	<?php } ?>
	
	<tr class="pmpro_settings_divider gateway gateway_square" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?>>
		<td colspan="2">
			<hr />
			<h2><?php esc_html_e( 'Other Square Settings', 'paid-memberships-pro' ); ?></h2>
		</td>
	</tr>
	<tr class="gateway gateway_square" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label for="square_billingaddress"><?php esc_html_e( 'Show Billing Address Fields in PMPro Checkout Form', 'paid-memberships-pro' ); ?></label>
		</th>
		<td>
			<select id="square_billingaddress" name="square_billingaddress">
				<option value="0"
						<?php if ( empty( $values['square_billingaddress'] ) ) { ?>selected="selected"<?php } ?>><?php esc_html_e( 'No', 'paid-memberships-pro' ); ?></option>
				<option value="1"
						<?php if ( ! empty( $values['square_billingaddress'] ) ) { ?>selected="selected"<?php } ?>><?php esc_html_e( 'Yes', 'paid-memberships-pro' ); ?></option>
			</select>
			<p class="description"><?php echo wp_kses_post( __( "Square doesn't require billing address fields. Choose 'No' to hide them on the checkout page.", 'paid-memberships-pro' ) ); ?></p>
		</td>
	</tr>
	<tr class="gateway gateway_square" <?php if ( $gateway != "square" ) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label for="square_log"><?php esc_html_e( 'Logging', 'paid-memberships-pro' ); ?></label>
		</th>
		<td>
			<input type="checkbox" name="square_log" <?php checked( 'yes', $values['square_log'] ); ?> value="yes"> <?php _e( 'Enable logging of all Square events', 'pmpro-square' ); ?>
		</td>
	</tr>

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

	public function enqueue_scripts() {
		global $gateway, $pmpro_level, $current_user, $pmpro_requirebilling, $pmpro_pages, $pmpro_currency;

		if ( ! pmpro_is_checkout() ) {
			return;
		}

		$initial_payment = floatval( $pmpro_level->initial_payment );
		
		if ( $this->gateway_environment == 'live' ) {
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
				'level_id'    => $pmpro_level->id,
				'amount' => $initial_payment,
				'currency' => $pmpro_currency,
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
	 * Use our own payment fields at checkout.
	 */
	public function include_payment_information_fields() {

		//include ours
		?>
		<fieldset id="pmpro_payment_information_fields" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset', 'pmpro_payment_information_fields' ) ); ?>"> SQUARE PAYMENT FIELDS
			<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card' ) ); ?>">
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
					<legend class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_legend' ) ); ?>">
						<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_heading pmpro_font-large' ) ); ?>"><?php esc_html_e('Payment Information', 'paid-memberships-pro' ); ?></h2>
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

	/*
	public private function init_order() {
		global $gateway, $pmpro_level, $current_user, $pmpro_requirebilling, $pmpro_pages, $pmpro_currency;

		$this->log( 'init order called' );

		if ( empty( $_POST['security'] ) ) {
			wp_send_json_error( array( 'reasons' => __( 'Failed to pass security', 'sunshine-photo-cart' ) ) );
			return;
		}

		if ( empty( $_POST['source_id'] ) ) {
			wp_send_json_error( array( 'reasons' => __( 'No source ID', 'sunshine-photo-cart' ) ) );
			return;
		}

		$source_id = sanitize_text_field( $_POST['source_id'] );
		$level_id = intval( $_POST['level_id'] );

		// Create the customer in square
		
		$amount_money = new \Square\Models\Money();
		$amount_money->setAmount( 1000 );
		$amount_money->setCurrency( $pmpro_currency );

		$body = new \Square\Models\CreatePaymentRequest( $source_id, $this->get_idempotency_key() );
		$body->setAmountMoney( $amount_money );
		$body->setLocationId( $this->location_id );

		$api_response = $this->client->getPaymentsApi()->createPayment( $body );
		$this->log( $api_response );

		if ( $api_response->isSuccess() ) {
			$payment = $api_response->getResult()->getPayment();
			wp_send_json_success( array( 'payment_id' => $payment->getId() ) );
		} else {
			$errors = $api_response->getErrors();
			wp_send_json_error( array( 'reasons' => $errors ) );
		}

	}
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
		$existing_subscription_plan_variations = get_option( 'pmpro_square_subscription_plan_variations_' . $this->gateway_environment . '_' . $membership_level->id );
	
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
	 * Process checkout.
	 */
	public function process( &$order ) {
		global $pmpro_currency, $current_user;

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		$this->log( $order, '=============== NEW ORDER' );

		// clean up a couple values
		$order->payment_type = 'Square';
		$order->CardType     = '';
		$order->cardtype     = '';
		$order->status = 'token';

		$this->log( $_POST, 'Square Processing' );

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
			$square_customer_id = get_user_meta( $user_id, 'pmpro_square_customer_id_' . $this->gateway_environment, true );
			if ( ! $square_customer_id ) {

				$customer_request = new \Square\Models\CreateCustomerRequest();
				$customer_request->setGivenName( $user->first_name );
				$customer_request->setFamilyName( $user->last_name );
				$customer_request->setEmailAddress( $user->user_email );
				$customer_response = $this->client->getCustomersApi()->createCustomer( $customer_request );
				if ( $customer_response->isSuccess()) {
					$square_customer = $customer_response->getResult()->getCustomer();
					$square_customer_id = $square_customer->getId();
					$this->log( $square_customer, 'Customer created' );
					update_user_meta( $user_id, 'pmpro_square_customer_id_' . $this->gateway_environment, $square_customer_id );
				} else {
					$errors = $customer_response->getErrors();
					$this->log( $errors, 'Customer NOT created' );
					return false;
				}

			}

		} else {
			// No user account was available, bail.
			return false;
		}

		$this->log( 'SQUARE CUSTOMER ID: ' . $square_customer_id );

		// Setup billing address for API request if present.
		if ( ! empty( $order->billing->name ) ) {
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

			$this->log( $order, 'Process one time payment' );

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
				$this->log( $result );
				return true;
			} else {
				$this->log( $api_response, 'ERRORS' );
				$errors = $api_response->getErrors();
				foreach ( $errors as $error ) {
					$order->error      .= __( 'Error processing payment', 'pmpro-square' ) . ': ' . $error->getCode();
					$order->shorterror = $error->getCode();
				}
				return false;
			}

		} else {

			// Otherwise, it is a subscription and need to set that up.
			$this->log( 'Process subscription' );

			/******************
			Get the current Square Subscription Plan ID for this Membership Level.
			******************/
			$square_subscription_plan_id = pmpro_getOption( 'square_subscription_plan_id_' . $this->gateway_environment . '_' . $order->membership_level->id );
			if ( empty( $square_subscription_plan_id ) ) {

				$this->log( 'No Square Subscription Plan for this Membership Level' );

				// Create a new subscription plan for the membership level since it does not yet exist.
				$square_subscription_plan_id = $this->create_subscription_plan( $order->membership_level );
				if ( ! $square_subscription_plan_id ) {
					$this->log( 'Failed to create subscription plan in Square' );
					wp_die( __( 'Failed to create subscription plan in Square', 'pmpro-square' ) );
					exit;
				}

			}

			/******************
			Get the current Square Subscription Plan Variation ID for this Membership Level.
			******************/
			$square_subscription_plan_variation_id = $this->get_subscription_plan_variation_id( $order->membership_level, $order );
			if ( ! $square_subscription_plan_variation_id ) {

				$this->log( 'Since no subscription plan variation id found, try creating a new one...' );

				// Create a new subscription plan variation for the membership level since it does not yet exist.
				$square_subscription_plan_variation_id = $this->create_subscription_plan_variation( $square_subscription_plan_id, $order->membership_level, $order );
				if ( ! $square_subscription_plan_variation_id ) {
					$this->log( 'Failed at creating subscription plan variation in square' );
					wp_die( __( 'Failed to create subscription plan variation in Square', 'pmpro-square' ) );
					exit;
				}

			}

			/******************
			Add the square token to the card on file for this customer.
			******************/
			$card = new \Square\Models\Card();
			$card->setCustomerId( $square_customer_id );
			if ( ! empty( $address ) ) {
				if ( ! empty( $order->billing->name ) ) {
					$card->setCardholderName( $order->billing->name );
				}
				$card->setBillingAddress( $address );
			}

			$body = new \Square\Models\CreateCardRequest(
				$this->get_idempotency_key(),
				//$square_token,
				'cnon:card-nonce-ok',
				$card
			);

			$api_response = $this->client->getCardsApi()->createCard( $body );

			if ( $api_response->isSuccess() ) {
				$this->log( 'Card added to file of customer' );
				$result = $api_response->getResult();
				$card = $result->getCard();
				$square_card_id = $card->getId();
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
			} else {
				$this->log( $body, 'card body' );
				$this->log( $api_response, 'Card request' );
				$errors = $api_response->getErrors();
				foreach ( $errors as $error ) {
					$order->error      .= __( 'Error processing payment', 'pmpro-square' ) . ': ' . $error->getCode();
					$order->shorterror = $error->getCode();
				}
				return false; // Card MUST be saved on file, only way to set up subs in Square.
			}

			/******************
			Put all the pieces together to make the final subscription request.
			******************/
			$body = new \Square\Models\CreateSubscriptionRequest( $this->location_id, $square_customer_id );
			$body->setIdempotencyKey( $this->get_idempotency_key() );
			$body->setPlanVariationId( $square_subscription_plan_variation_id );
			$body->setCardId( $square_card_id );

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
				$this->log( $subscription, 'SUBSCRIPTION SUCCESS!' );
				$order->subscription_transaction_id = $subscription->getId();
				// We need to set the payment ID in the webhook since the API does not return any data on invoices/payments yet.
				// Square can take a few minutes to actually process the first payment.			
				$order->status = 'success';
				return true;
			} else {
				$this->log( $api_response, 'Subscription FAIL' );
				$errors = $api_response->getErrors();
				$order->error      .= __( 'Could not establish customer subscription in Square', 'pmpro-square' );
				$order->shorterror = $errors[0]->getCode();
				return false;
			}
				
		}

		// If we got here, something didn't go correctly.
		return false;

	}

	private function get_idempotency_key( $key_input = '', $append_key_input = true ) {

		if ( '' === $key_input ) {
			$key_input = uniqid( '', false );
		}

		return substr( apply_filters( 'pmpro_square_idempotency_key', sha1( get_option( 'siteurl' ) . $key_input ) . ( $append_key_input ? ':' . $key_input : '' ) ), -40 );
	}


	private function get_application_id() {

		if ( empty( $this->gateway_environment ) ) {
			$this->gateway_environment = pmpro_getOption( 'gateway_environment' );
		}
		$application_id = pmpro_getOption( 'square_' . $this->gateway_environment . '_application_id' );
		if ( $application_id ) {
			return $application_id;
		}
		return null;
		
	}

	private function get_location_id( $environment = '' ) {

		if ( empty( $environment ) ) {
			$environment = pmpro_getOption( 'gateway_environment' );
		}
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

	public function cancel_subscription( $subscription ) {

		$this->log( 'Attempting to cancel subscription...' );
		$subscription_id = $subscription->get_subscription_transaction_id();
		$api_response = $client->getSubscriptionsApi()->cancelSubscription( $subscription_id );

		if ( $api_response->isSuccess() ) {
			$this->log( 'Subscription cancelled at Square via API: ' . $subscription_id );
			return true;
		}

		$this->log( 'Subscription failed to cancel at Square via API: ' . $subscription_id );
		return false;

	}

	public function webhook_listener() {
		global $wpdb;

		if ( empty( $_GET['pmpro_square_webhook'] ) ) {
			return;
		}

		$this->log( '========= WEBHOOK LISTENER' );

		$environment = get_option( 'pmpro_gateway_environment' );
		$webhook_data = get_option( 'pmpro_square_webhook_' . $environment );
		if ( empty( $webhook_data ) ) {
			$this->log( 'No webhook settings data' );
			http_response_code( 403 );
			exit;
		}

		$headers = apache_request_headers();
		//$this->log( $headers, 'HEADERS' );
		$signature = $headers["X-Square-Hmacsha256-Signature"];
		
		// Signature completely empty (Throw a warning/bail) /// Temporary.
		if ( empty( $signature ) ) {
			$this->log( 'No signature' );
			http_response_code( 403 );
			exit;
		}

		$body = '';   
		$handle = fopen( 'php://input', 'r' );
		while( ! feof( $handle ) ) {
			$body .= fread( $handle, 1024 );
		}

		if ( ! \Square\Utils\WebhooksHelper::isValidWebhookEventSignature( $body, $signature, $webhook_data['signature_key'], $this->get_webhook_url() ) ) {
			$this->log( $_POST, "Error verifying webhook signature" );
			http_response_code( 403 );
			exit;
		}

		$webhook = json_decode( $body, true );
		$this->log( $webhook );

		if ( $webhook['type'] === 'invoice.payment_made' ) {

			$this->log( 'SQUARE WEBHOOK invoice.payment_made' );

			$invoice = $webhook['data']['object']['invoice'];

			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $wpdb->pmpro_membership_orders WHERE subscription_transaction_id = %s", $invoice['subscription_id'] ) );
			if ( empty( $order_id ) ) {
				$this->log( 'Webhook could not find order with square subscription ID: ' . $invoice['subscription_id'] );
				exit();
			}
	
			$order = new MemberOrder( $order_id );
	
			if ( $invoice['status'] !== 'PAID' ) {
				return;
			}

			$order->payment_transaction_id = $invoice['order_id'];
			$order->saveOrder();

			$this->log( '=========== ORDER SAVED!' );

			pmpro_pull_checkout_data_from_order( $order );
			return pmpro_complete_async_checkout( $order );

		}

		http_response_code( 200 );
		exit;

	}

	private function log( $message, $prefix = '' ) {

		if ( get_option( "pmpro_square_log" ) !== 'yes' ) {
			return;
		}

		$date = current_time( 'y-m-d H:i:s' );

		$fp = fopen( $this->log_file, 'a' );

		if ( $prefix ) {
			if ( is_array( $prefix ) || is_object( $prefix ) ) {
				$prefix = print_r( $prefix, true );
			}
			$prefix = $date . ': ' . $prefix . "\n";
			fwrite( $fp, $prefix );
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true );
		}
		$log_message = $date . ': ' . $message;
		if ( is_user_logged_in() ) {
			$log_message .= ' (User ID: ' . get_current_user_id() . ')';
		}
		$log_message .= "\n";
		fwrite( $fp, $log_message );
		fclose( $fp );

	}

}
