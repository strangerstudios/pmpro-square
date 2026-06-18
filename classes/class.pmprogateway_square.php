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
	private $application_id;
	private $location_id;
	private $personal_access_token;
	public $client;
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

		// Enable admin-initiated refunds from the order screen.
		add_filter( 'pmpro_allowed_refunds_gateways', array( 'PMProGateway_square', 'allowed_refund_gateways' ) );
		add_filter( 'pmpro_process_refund_square', array( 'PMProGateway_square', 'process_refund' ), 10, 2 );

		// Clear cached plan variations if the Square Application ID changes.
		add_action( 'admin_init', array( $this, 'maybe_change_app_id' ) );

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
	public static function pmpro_payment_options( $options ) {
		//get square options
		$square_options = self::getGatewayOptions();

		//merge with others.
		$options = array_merge( $square_options, $options );

		return $options;
	}

	/**
	 * Description shown for this gateway on the Payment Settings page.
	 *
	 * @since TBD
	 *
	 * @return string The gateway description.
	 */
	public static function get_description_for_gateway_settings() {
		return esc_html__( 'Accept credit card, debit card, and digital wallet payments with Square. Square offers secure online checkout and competitive transaction pricing.', 'pmpro-square' );
	}

	/**
	 * Display this gateway's settings fields on the Payment Settings page.
	 *
	 * Overrides the base gateway method (PMPro 3.5+) so the fields render
	 * natively instead of relying on the legacy pmpro_payment_option_fields
	 * filter and its compatibility shim.
	 *
	 * @since TBD
	 */
	public static function show_settings_fields() {
		// Build the values array from saved options.
		$values = array();
		foreach ( self::getGatewayOptions() as $key ) {
			$values[ $key ] = get_option( 'pmpro_' . $key );
		}

		$sandbox_locations = get_option( 'pmpro_square_locations_sandbox' );
		$live_locations    = get_option( 'pmpro_square_locations_live' );
		$sandbox_webhook   = get_option( 'pmpro_square_webhook_sandbox' );
		$live_webhook      = get_option( 'pmpro_square_webhook_live' );
		?>
		<div id="pmpro_square_sandbox" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Square Sandbox Settings', 'pmpro-square' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_square">
							<th scope="row" valign="top">
								<label for="square_sandbox_application_id"><?php esc_html_e( 'Sandbox Application ID', 'pmpro-square' ); ?></label>
							</th>
							<td>
								<input type="text" id="square_sandbox_application_id" name="square_sandbox_application_id" size="60" value="<?php echo esc_attr( $values['square_sandbox_application_id'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Enter the Application ID from Square.', 'pmpro-square' ); ?></p>
							</td>
						</tr>
						<tr class="gateway gateway_square">
							<th scope="row" valign="top">
								<label for="square_sandbox_access_token"><?php esc_html_e( 'Sandbox Access Token', 'pmpro-square' ); ?></label>
							</th>
							<td>
								<input type="text" id="square_sandbox_access_token" name="square_sandbox_personal_access_token" size="60" value="<?php echo esc_attr( $values['square_sandbox_personal_access_token'] ); ?>" autocomplete="off" class="pmpro-admin-secure-key" />
								<p class="description"><?php esc_html_e( 'Enter the Access Token from Square.', 'pmpro-square' ); ?></p>
							</td>
						</tr>
						<?php if ( ! empty( $values['square_sandbox_personal_access_token'] ) ) { ?>
							<tr class="gateway gateway_square">
								<th scope="row" valign="top">
									<label for="square_sandbox_location_id"><?php esc_html_e( 'Sandbox Location', 'pmpro-square' ); ?></label>
								</th>
								<td>
									<select id="square_sandbox_location_id" name="square_sandbox_location_id">
										<option value=""><?php esc_html_e( 'Default location', 'pmpro-square' ); ?></option>
										<?php
										if ( ! empty( $sandbox_locations ) ) {
											foreach ( $sandbox_locations as $id => $name ) {
												echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $values['square_sandbox_location_id'], false ) . '>' . esc_html( $name ) . '</option>';
											}
										}
										?>
									</select>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=sandbox' ), 'pmpro_square_refresh_locations' ) ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' ); ?></a>
									<p class="description"><?php esc_html_e( 'A location is where your transactions occur. Select "Default Location" if you are not sure.', 'pmpro-square' ); ?></p>
								</td>
							</tr>
							<tr class="gateway gateway_square">
								<th scope="row" valign="top">
									<label for="square_sandbox_webhooks"><?php esc_html_e( 'Sandbox Webhooks', 'pmpro-square' ); ?></label>
								</th>
								<td>
									<?php if ( $sandbox_webhook ) { ?>
										<div class="notice notice-success inline"><p>
											<?php echo esc_html( implode( ', ', $sandbox_webhook['event_types'] ) ); ?>
											<br /><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_disable_webhooks=sandbox' ), 'pmpro_square_disable_webhooks' ) ); ?>"><?php esc_html_e( 'Disable webhooks', 'pmpro-square' ); ?></a>
										</p></div>
									<?php } else { ?>
										<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=sandbox' ), 'pmpro_square_webhooks' ) ); ?>" class="button"><?php esc_html_e( 'Generate webhooks', 'pmpro-square' ); ?></a></p>
										<p><?php esc_html_e( 'Webhook URL', 'pmpro-square' ); ?>: <code><?php echo esc_html( self::get_webhook_url() ); ?></code></p>
									<?php } ?>
								</td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
		<div id="pmpro_square_live" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Square Live Settings', 'pmpro-square' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_square">
							<th scope="row" valign="top">
								<label for="square_live_application_id"><?php esc_html_e( 'Live Application ID', 'pmpro-square' ); ?></label>
							</th>
							<td>
								<input type="text" id="square_live_application_id" name="square_live_application_id" size="60" value="<?php echo esc_attr( $values['square_live_application_id'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Enter the Application ID from Square.', 'pmpro-square' ); ?></p>
							</td>
						</tr>
						<tr class="gateway gateway_square">
							<th scope="row" valign="top">
								<label for="square_live_access_token"><?php esc_html_e( 'Live Access Token', 'pmpro-square' ); ?></label>
							</th>
							<td>
								<input type="text" id="square_live_access_token" name="square_live_personal_access_token" size="60" value="<?php echo esc_attr( $values['square_live_personal_access_token'] ); ?>" autocomplete="off" class="pmpro-admin-secure-key" />
								<p class="description"><?php esc_html_e( 'Enter the Access Token from Square.', 'pmpro-square' ); ?></p>
							</td>
						</tr>
						<?php if ( ! empty( $values['square_live_personal_access_token'] ) ) { ?>
							<tr class="gateway gateway_square">
								<th scope="row" valign="top">
									<label for="square_live_location_id"><?php esc_html_e( 'Live Location', 'pmpro-square' ); ?></label>
								</th>
								<td>
									<select id="square_live_location_id" name="square_live_location_id">
										<option value=""><?php esc_html_e( 'Default location', 'pmpro-square' ); ?></option>
										<?php
										if ( ! empty( $live_locations ) ) {
											foreach ( $live_locations as $id => $name ) {
												echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $values['square_live_location_id'], false ) . '>' . esc_html( $name ) . '</option>';
											}
										}
										?>
									</select>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_refresh_locations=live' ), 'pmpro_square_refresh_locations' ) ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' ); ?></a>
									<p class="description"><?php esc_html_e( 'A location is where your transactions occur. Select "Default Location" if you are not sure.', 'pmpro-square' ); ?></p>
								</td>
							</tr>
							<tr class="gateway gateway_square">
								<th scope="row" valign="top">
									<label for="square_live_webhooks"><?php esc_html_e( 'Live Webhooks', 'pmpro-square' ); ?></label>
								</th>
								<td>
									<?php if ( $live_webhook ) { ?>
										<div class="notice notice-success inline"><p>
											<?php echo esc_html( implode( ', ', $live_webhook['event_types'] ) ); ?>
											<br /><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_disable_webhooks=live' ), 'pmpro_square_disable_webhooks' ) ); ?>"><?php esc_html_e( 'Disable webhooks', 'pmpro-square' ); ?></a>
										</p></div>
									<?php } else { ?>
										<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&pmpro_square_webhooks=live' ), 'pmpro_square_webhooks' ) ); ?>" class="button"><?php esc_html_e( 'Generate webhooks', 'pmpro-square' ); ?></a></p>
										<p><?php esc_html_e( 'Webhook URL', 'pmpro-square' ); ?>: <code><?php echo esc_html( self::get_webhook_url() ); ?></code></p>
									<?php } ?>
								</td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
		<div id="pmpro_square_other" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Other Square Settings', 'pmpro-square' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_square">
							<th scope="row" valign="top">
								<label for="square_billingaddress"><?php esc_html_e( 'Show Billing Address Fields in PMPro Checkout Form', 'pmpro-square' ); ?></label>
							</th>
							<td>
								<select id="square_billingaddress" name="square_billingaddress">
									<option value="0" <?php selected( empty( $values['square_billingaddress'] ) ); ?>><?php esc_html_e( 'No', 'pmpro-square' ); ?></option>
									<option value="1" <?php selected( ! empty( $values['square_billingaddress'] ) ); ?>><?php esc_html_e( 'Yes', 'pmpro-square' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( "Square doesn't require billing address fields. Choose 'No' to hide them on the checkout page.", 'pmpro-square' ); ?></p>
							</td>
						</tr>
						<tr class="gateway gateway_square">
							<th scope="row" valign="top">
								<label for="square_log"><?php esc_html_e( 'Logging', 'pmpro-square' ); ?></label>
							</th>
							<td>
								<label><input type="checkbox" id="square_log" name="square_log" value="yes" <?php checked( 'yes', $values['square_log'] ); ?> /> <?php esc_html_e( 'Enable logging of all Square events', 'pmpro-square' ); ?></label>
								<?php
								if ( ! empty( $values['square_log'] ) ) {
									$log_file_name = get_option( 'pmpro_square_log_file_name' );
									if ( ! empty( $log_file_name ) ) {
										$uploads = wp_upload_dir();
										?>
										<br /><br /><a href="<?php echo esc_url( trailingslashit( $uploads['baseurl'] ) . $log_file_name ); ?>" target="_blank" class="button"><?php esc_html_e( 'View log file', 'pmpro-square' ); ?></a>
										<?php
									}
								}
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save this gateway's settings fields from the Payment Settings page.
	 *
	 * Overrides the base gateway method (PMPro 3.5+). Only saves options
	 * specific to this gateway; global options (gateway, environment,
	 * currency, tax, etc.) are saved by PMPro core on the main settings page.
	 *
	 * @since TBD
	 */
	public static function save_settings_fields() {
		// Options that are global to all gateways and saved elsewhere by core.
		$global_options = array(
			'sslseal',
			'nuclear_HTTPS',
			'gateway_environment',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate',
		);

		foreach ( self::getGatewayOptions() as $option ) {
			if ( in_array( $option, $global_options, true ) ) {
				continue;
			}
			pmpro_setOption( $option );
		}
	}

	/**
	 * Setup the connection to the API client and get base options.
	 */
	public function setup( $environment = '' ) {

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
	private function is_ready() {

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
	 * When the payment settings are saved, clear cached subscription plans and plan
	 * variations for any environment whose Square Application ID has changed. The old
	 * catalog objects belong to a different Square account/application and can no longer
	 * be used, so they must be recreated on the next checkout.
	 *
	 * @since TBD
	 */
	public function maybe_change_app_id() {

		// Only act when the payment settings are being saved.
		if ( empty( $_REQUEST['savesettings'] ) ) {
			return;
		}

		// Check permissions.
		if ( ! function_exists( 'current_user_can' ) || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'pmpro_paymentsettings' ) ) ) {
			return;
		}

		// Verify the settings nonce.
		if ( empty( $_REQUEST['pmpro_paymentsettings_nonce'] ) || ! check_admin_referer( 'savesettings', 'pmpro_paymentsettings_nonce' ) ) {
			return;
		}

		// Compare each saved Application ID to the submitted value (this runs before the new
		// values are saved, so get_option() still holds the previous Application ID).
		$sandbox_app_id     = get_option( 'pmpro_square_sandbox_application_id' );
		$new_sandbox_app_id = isset( $_REQUEST['square_sandbox_application_id'] ) ? sanitize_text_field( $_REQUEST['square_sandbox_application_id'] ) : '';
		if ( $sandbox_app_id && $sandbox_app_id !== $new_sandbox_app_id ) {
			$this->clear_plan_variations( 'sandbox' );
		}

		$live_app_id     = get_option( 'pmpro_square_live_application_id' );
		$new_live_app_id = isset( $_REQUEST['square_live_application_id'] ) ? sanitize_text_field( $_REQUEST['square_live_application_id'] ) : '';
		if ( $live_app_id && $live_app_id !== $new_live_app_id ) {
			$this->clear_plan_variations( 'live' );
		}
	}

	/**
	 * Clear the cached subscription plan and plan variations for an environment.
	 *
	 * @since TBD
	 *
	 * @param string $environment The gateway environment ('sandbox' or 'live').
	 */
	private function clear_plan_variations( $environment ) {
		$this->log( 'Clearing cached subscription plans/variations for ' . $environment );

		$levels = pmpro_getAllLevels( true, true );
		foreach ( $levels as $level ) {
			delete_option( 'pmpro_square_subscription_plan_variations_' . $environment . '_' . $level->id );
			delete_option( 'pmpro_square_subscription_plan_id_' . $environment . '_' . $level->id );
		}
	}

	/**
	 * Refreshes the list of available locations for an environment on a manual request.
	 */
	public function refresh_locations_manual() {

		if ( empty( $_GET['pmpro_square_refresh_locations'] ) || ! current_user_can( 'manage_options' ) ) {
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

		// Use the environment posted with the settings save when present (it may have just
		// been changed); otherwise fall back to the saved gateway environment.
		$environment = ! empty( $_POST['gateway_environment'] ) ? sanitize_text_field( $_POST['gateway_environment'] ) : $this->get_environment();

		$existing_locations = get_option( 'pmpro_square_locations_' . $environment );

		if ( empty( $existing_locations ) ) {
			$this->refresh_locations( $environment );
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
	public static function get_webhook_url() {
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

		// Use the environment posted with the settings save when present (it may have just
		// been changed); otherwise fall back to the saved gateway environment.
		$environment = ! empty( $_POST['gateway_environment'] ) ? sanitize_text_field( $_POST['gateway_environment'] ) : $this->get_environment();

		$existing_webhooks = get_option( 'pmpro_square_webhook_' . $environment );
		if ( empty( $existing_webhooks ) ) {
			$this->log( 'No webhooks in ' . $environment . ', creating...' );
			$this->create_webhooks( $environment );
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


	public function enqueue_scripts() {
		global $gateway, $pmpro_level, $current_user, $pmpro_requirebilling, $pmpro_pages, $pmpro_currency;

		// Don't enqueue Square's scripts if the gateway isn't configured.
		if ( ! $this->is_ready() ) {
			return;
		}

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
				'rest_url'        => get_rest_url(),
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
					<?php if ( $this->is_ready() ) { ?>
						<div id="pmpro-square-card-container">
							<div id="pmpro-square-card-fields"></div>
							<div id="pmpro-square-status"></div>
						</div>
					<?php } elseif ( current_user_can( 'manage_options' ) ) { ?>
						<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_message pmpro_error' ) ); ?>">
							<?php esc_html_e( 'Square is not fully configured, so the payment fields cannot be displayed. This message is only shown to administrators. Please complete the Square gateway settings.', 'pmpro-square' ); ?>
						</div>
					<?php } ?>
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

		// We would need to create a new subscription plan variation if we have different number of days to delay based on sub delay.
		$subscription_delay_days = $this->calculate_subscription_delay( $order->membership_level, $order );
		if ( $subscription_delay_days ) {
			$membership_level_data['delay_days'] = $subscription_delay_days;
		}
	
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
	 * Calculates the subscription delay in days.
	 **/
	private function calculate_subscription_delay( $membership_level, $order ) {

		$subscription_delay_days = 0;

		// Get the expected start date based on the membership level's cycle.
		$expected_profile_start_date = date_i18n( 'Y-m-d', strtotime( '+ ' . $membership_level->cycle_number . ' ' . $membership_level->cycle_period ) );

		// Calculate using our fancy pmpro_calculate_profile_start_date() which other add-ons can hook into to get the actual profile start date.
		$actual_profile_start_date = pmpro_calculate_profile_start_date( $order, 'Y-m-d' );

		// If they are different, then we have a custom subscription delay!
		if ( $expected_profile_start_date != $actual_profile_start_date ) {
			// Calculate number of days difference between today and actual profile start date.
			$today = date_i18n( 'Y-m-d' );
			$subscription_delay_days = ceil( abs( strtotime( $today ) - strtotime( $actual_profile_start_date ) ) / 86400 );
		}

		return $subscription_delay_days;

	}

	/**
	 * Prep the phase data when creating a new subscription.
	 **/
	private function create_subscription_phases( $membership_level, $order ) {
		global $pmpro_currency;

		$this->log( 'Starting create_subscription_phases...' );

		$phases = array();

		$subscription_delay_days = $this->calculate_subscription_delay( $membership_level, $order );
		
		$ordinal = 0; // Phase number in the sequence.

		// Define the initial price phase if set and different from recurring billing amount.
		if ( $membership_level->initial_payment && $membership_level->initial_payment != $membership_level->billing_amount ) {

			// We will pass the tax % later so do not need to add it to the total here.
			$initial_payment = $membership_level->initial_payment * 100; // Square works in cents.

			$initial_price_money = new \Square\Models\Money();
			$initial_price_money->setAmount( $initial_payment );
			$initial_price_money->setCurrency( $pmpro_currency );

			$initial_pricing = new \Square\Models\SubscriptionPricing();
			$initial_pricing->setType( 'STATIC' );
			$initial_pricing->setPriceMoney( $initial_price_money );

			// If we have subscription delay, use that many days for this initial payment phase.
			if ( $subscription_delay_days ) {
				$initial_phase = new \Square\Models\SubscriptionPhase( 'DAILY' );
				$initial_phase->setPeriods( $subscription_delay_days );
			} else {
				$cadence = $this->get_cadence( $membership_level->cycle_period, $membership_level->cycle_number );
				if ( ! $cadence ) {
					$error_message = sprintf( __( 'Invalid cadence for initial payment (Period: %s, Limit: %s)', 'pmpro-square' ), $membership_level->cycle_period, $membership_level->cycle_number );
					$this->log( $error_message );
					return false;
				}
				$initial_phase = new \Square\Models\SubscriptionPhase( $cadence );
				$initial_phase->setPeriods( 1 );
			}

			$initial_phase->setOrdinal( $ordinal++ ); // The order in which the phase is to be processed.
			$initial_phase->setPricing( $initial_pricing );

			$phases[] = $initial_phase;
		}

		// Add the trial if set and we do not have a subscription delay.
		if ( empty( $subscription_delay_days ) && ! empty( $membership_level->trial_amount ) && ! empty( $membership_level->trial_limit ) ) {

			$trial_payment = $membership_level->trial_amount;
			// We will pass the tax % later so do not need to add it to the total here.
			$trial_payment = $trial_payment * 100; // Square works in cents.

			$trial_price_money = new \Square\Models\Money();
			$trial_price_money->setAmount( $trial_payment );
			$trial_price_money->setCurrency( $pmpro_currency );

			$trial_pricing = new \Square\Models\SubscriptionPricing();
			$trial_pricing->setType( 'STATIC' );
			$trial_pricing->setPriceMoney( $trial_price_money );

			$cadence = $this->get_cadence( $membership_level->cycle_period, $membership_level->cycle_number );
			if ( ! $cadence ) {
				$error_message = sprintf( __( 'Invalid cadence for trial payment (Period: %s, Limit: %s)', 'pmpro-square' ), $membership_level->cycle_period, $membership_level->cycle_number );
				$this->log( $error_message );
				return false;
			}
	
			$trial_phase = new \Square\Models\SubscriptionPhase( $cadence );
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
		
		$cadence = $this->get_cadence( $membership_level->cycle_period, $membership_level->cycle_number );
		if ( ! $cadence ) {
			$error_message = sprintf( __( 'Invalid cadence for monthly payment (Period: %s, Limit: %s)', 'pmpro-square' ), $membership_level->cycle_period, $membership_level->cycle_number );
			$this->log( $error_message );
			return false;
		}

		$subscription_phase = new \Square\Models\SubscriptionPhase( $cadence );
		$subscription_phase->setOrdinal( $ordinal++ ); // The order in which the phase is to be processed.
		$subscription_phase->setPricing( $recurring_pricing );

		// Set max number of billing periods.
		if ( ! empty( $membership_level->billing_limit ) ) {
			$subscription_phase->setPeriods( absint( $membership_level->billing_limit ) );
		}

		$phases[] = $subscription_phase;

		$this->log( $membership_level, 'Membership level' );
		$this->log( $phases, 'Phases' );

		return $phases;

	}

	/**
	 * Determine the proper cadence from very strict set of available for Square API.
	 * Reference: https://developer.squareup.com/reference/square/objects/SubscriptionPhase
	 **/
	private function get_cadence( $period, $number ) {

		$number = intval( $number ); // Force int for comparison.

		if ( $period == "Day" ) {
			if ( $number == 1 ) {
				return 'DAILY';
			} elseif ( $number == 30 ) {
				return 'THIRTY_DAYS';
			} elseif ( $number == 60 ) {
				return 'SIXTY_DAYS';
			} elseif ( $number == 90 ) {
				return 'NINETY_DAYS';
			}
			return false;
		} elseif ( $period == "Week" ) {
			if ( $number == 1 ) {
				return 'WEEKLY';
			} elseif ( $number == 2 ) {
				return 'EVERY_TWO_WEEKS';
			}
			return false;
		} elseif ( $period == "Month" ) {
			if ( $number == 1 ) {
				return 'MONTHLY';
			} elseif ( $number == 2 ) {
				return 'EVERY_TWO_MONTHS';
			} elseif ( $number == 3 ) {
				return 'QUARTERLY';
			} elseif ( $number == 4 ) {
				return 'EVERY_FOUR_MONTHS';
			} elseif ( $number == 6 ) {
				return 'EVERY_SIX_MONTHS';
			}
			return false;
		} elseif ( $period == "Year" ) {
			if ( $number == 1 ) {
				return 'ANNUAL';
			} elseif ( $number == 2 ) {
				return 'EVERY_TWO_YEARS';
			}
			return false;
		}
		return false;

	}

	/**
	 * Creating a new subscription plan variation.
	 **/
	private function create_subscription_plan_variation( $subscription_plan_id, $membership_level, $order ) {
		global $pmpro_currency;

		$this->log( 'Starting create_subscription_plan_variation...' );

		// Get the phases based on membership level settings.
		$phases = $this->create_subscription_phases( $membership_level, $order );
		if ( ! $phases ) {
			return false;
		}

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
			
			$membership_level_array = (array) $membership_level;

			// We need to add the # of delayed days as part of the plan variation stored locally for later comparison to see if a new one is needed.
			$subscription_delay_days = $this->calculate_subscription_delay( $membership_level, $order );
			if ( $subscription_delay_days ) {
				$membership_level_array['delay_days'] = $subscription_delay_days;
			}	
			$existing_plan_variations[ $subscription_plan_variation_id ] = $membership_level_array;
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
		$this->log( 'Prepping billing address' );
		$address = new \Square\Models\Address();
		// Pull from POST data as they are separate whereas order combines into single name.
		if ( ! empty( $_POST['bfirstname'] ) ) {
			$address->setFirstName( sanitize_text_field( $_POST['bfirstname'] ) );
		}
		if ( ! empty( $_POST['blastname'] ) ) {
			$address->setLastName( sanitize_text_field( $_POST['blastname'] ) );
		}
		if ( ! empty( $order->billing->street ) ) {
			$address->setAddressLine1( $order->billing->street );
			$address->setAddressLine2( $order->billing->street2 );
			$address->setLocality( $order->billing->city );
			$address->setAdministrativeDistrictLevel1( $order->billing->state );
			$address->setPostalCode( $order->billing->zip );
			$address->setCountry( $order->billing->country );
		}

		// One-time payments.
		if ( ! pmpro_isLevelRecurring( $order->membership_level ) ) {

			$this->log( 'Process one time payment...' );

			$initial_subtotal       = $order->subtotal;
			$initial_tax            = $order->getTaxForPrice( $initial_subtotal );
			$initial_payment_amount = pmpro_round_price( (float) $initial_subtotal + (float) $initial_tax );
			$initial_payment_amount = $initial_payment_amount * 100; // Square works in cents.

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
			$body->setBillingAddress( $address );
			$body->setNote( $order->membership_level->name );

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
					$order->error = __( 'Failed to create subscription plan in Square', 'pmpro-square' );
					return false;
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
					$order->error = __( 'Failed to create subscription plan variation in Square', 'pmpro-square' );
					return;
				}

			}

			/******************
			Add the square token to the card on file for this customer.
			******************/
			$card = new \Square\Models\Card();
			$card->setCustomerId( $square_customer_id );
			$card->setBillingAddress( $address );
			if ( ! empty( $order->billing->name ) ) {
				$card->setCardholderName( $order->billing->name );
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

			$subscription_delay_days = $this->calculate_subscription_delay( $order->membership_level, $order );
			// If no initial payment, then there is a free trial and need to set a future start date equal to one length of the cycle period.
			if ( empty( $order->membership_level->initial_payment ) && empty( $subscription_delay_days ) ) {
				$start_date = date( 'Y-m-d', strtotime( '+' . $order->membership_level->cycle_number . ' ' . $order->membership_level->cycle_period ) );
				$body->setStartDate( $start_date );
				$this->log( 'Setting delayed start date for trial period: ' . $start_date );
			} elseif ( empty( $order->membership_level->initial_payment ) && ! empty( $subscription_delay_days ) ) {
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

		// Bail gracefully if the API client could not be configured (e.g. missing
		// credentials) so a sync attempt never fatals.
		if ( empty( $this->client ) ) {
			$this->log( 'Could not sync subscription - Square API client is not configured.' );
			return __( 'Square API client is not configured.', 'pmpro-square' );
		}

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

				// A per-subscription price override (e.g. set by editing the price of this
				// subscription in the Square dashboard) takes precedence over the shared plan
				// variation's price. Square stores this as a flat amount on the subscription.
				$price_override = $square_subscription->getPriceOverrideMoney();
				if ( ! empty( $price_override ) && null !== $price_override->getAmount() ) {
					$update_array['billing_amount'] = (float) $price_override->getAmount() / 100;
				}

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
	 * Add Square to the list of gateways that support admin-initiated refunds.
	 *
	 * @since TBD
	 *
	 * @param array $gateways Gateways that support refunds.
	 * @return array
	 */
	public static function allowed_refund_gateways( $gateways ) {
		if ( ! in_array( 'square', $gateways, true ) ) {
			$gateways[] = 'square';
		}
		return $gateways;
	}

	/**
	 * Process a refund for an order at Square (admin-initiated, from the order screen).
	 *
	 * Refunds the full order total against the order's Square payment. The order's
	 * payment_transaction_id is a Square payment ID for one-time payments, or a Square
	 * order ID for recurring orders recorded via webhook - we resolve the latter to a
	 * payment ID before refunding.
	 *
	 * @since TBD
	 *
	 * @param bool        $success Whether a refund has already been processed.
	 * @param MemberOrder $order   The order to refund.
	 * @return bool
	 */
	public static function process_refund( $success, $order ) {
		// Another callback already handled this refund.
		if ( $success ) {
			return $success;
		}

		if ( empty( $order->payment_transaction_id ) ) {
			$order->add_order_note( __( 'Admin: Could not process refund. No payment transaction ID found on this order.', 'pmpro-square' ) );
			return false;
		}

		$gateway = new self();
		$gateway->setup();
		if ( empty( $gateway->client ) ) {
			$order->add_order_note( __( 'Admin: Could not process refund. The Square API client is not configured.', 'pmpro-square' ) );
			return false;
		}

		// Resolve the Square payment ID. One-time payments store the payment ID directly;
		// recurring orders store the Square order ID, which we resolve to its payment ID.
		$payment_id = $order->payment_transaction_id;
		$order_response = $gateway->client->getOrdersApi()->retrieveOrder( $order->payment_transaction_id );
		if ( $order_response->isSuccess() ) {
			$tenders = $order_response->getResult()->getOrder()->getTenders();
			if ( ! empty( $tenders ) && ! empty( $tenders[0]->getPaymentId() ) ) {
				$payment_id = $tenders[0]->getPaymentId();
			}
		}

		// Refund the full order total. Square works in the smallest currency unit (cents).
		global $pmpro_currency, $current_user;
		$amount_money = new \Square\Models\Money();
		$amount_money->setAmount( (int) round( (float) $order->total * 100 ) );
		$amount_money->setCurrency( ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD' );

		$body = new \Square\Models\RefundPaymentRequest( $gateway->get_idempotency_key(), $amount_money );
		$body->setPaymentId( $payment_id );
		$body->setReason( __( 'Refund issued from Paid Memberships Pro.', 'pmpro-square' ) );

		$api_response = $gateway->client->getRefundsApi()->refundPayment( $body );

		if ( ! $api_response->isSuccess() ) {
			$errors = $api_response->getErrors();
			$detail = ! empty( $errors[0] ) ? $errors[0]->getDetail() : __( 'Unknown error.', 'pmpro-square' );
			$order->add_order_note( sprintf( __( 'Admin: Square refund failed. %s', 'pmpro-square' ), $detail ) );
			$order->saveOrder();
			$gateway->log( 'Refund failed for order #' . $order->id . ': ' . print_r( $errors, 1 ) );
			return false;
		}

		$refund = $api_response->getResult()->getRefund();
		$status = $refund->getStatus();

		// A FAILED/REJECTED refund is not a success.
		if ( in_array( $status, array( 'FAILED', 'REJECTED' ), true ) ) {
			$order->add_order_note( sprintf( __( 'Admin: Square refund was %1$s. Refund ID: %2$s', 'pmpro-square' ), $status, $refund->getId() ) );
			$order->saveOrder();
			return false;
		}

		// Mark the order refunded immediately (PENDING refunds finalize asynchronously via
		// the refund webhook). Saving now also prevents the webhook from sending a duplicate
		// refund email, thanks to its "already refunded" guard.
		$order->status = 'refunded';
		$order->saveOrder();

		$order->add_order_note( sprintf( __( 'Admin: Order successfully refunded at Square by %1$s. Refund ID: %2$s (status: %3$s).', 'pmpro-square' ), $current_user->display_name, $refund->getId(), $status ) );

		// Notify the member and the admin.
		$user = get_user_by( 'id', $order->user_id );
		$myemail = new PMProEmail();
		$myemail->sendRefundedEmail( $user, $order );
		$myemail = new PMProEmail();
		$myemail->sendRefundedAdminEmail( $user, $order );

		$gateway->log( 'Refund created for order #' . $order->id . ': ' . $refund->getId() . ' status=' . $status );
		return true;
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
