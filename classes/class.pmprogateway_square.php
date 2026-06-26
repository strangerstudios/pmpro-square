<?php
//load classes init method
add_action( 'init', function(){
	$pmpro_square = new PMProGateway_square();
	$pmpro_square->init();
} );

class PMProGateway_square extends PMProGateway {

	/**
	 * The Square API version sent with every REST request.
	 */
	const SQUARE_API_VERSION = '2025-09-24';

	function __construct( $gateway = NULL ) {

		$this->gateway = $gateway;
		$this->gateway_environment = get_option( "pmpro_gateway_environment" );

		return $this->gateway;

	}

	/**
	 * Kick things off and setup all needed hooks
	 */
	public function init() {
		
		// Make sure Square is a gateway option.
		add_filter( 'pmpro_gateways', array( $this, 'pmpro_gateways' ));

		add_action( 'pmpro_after_saved_payment_options', array( $this, 'refresh_locations_auto' ) );

		// Square only supports one-time payments, so halt any recurring checkout before a charge
		// is made. Admin-facing warnings about recurring levels/discount codes are registered in
		// includes/admin-warnings.php.
		add_filter( 'pmpro_checkout_checks', array( $this, 'checkout_checks_recurring' ) );

		$gateway = pmpro_getGateway();

		if ( $gateway == "square" ) {

			// Enqueue Square JS on checkout page.
			add_action( 'pmpro_after_checkout_preheader', array( $this, 'enqueue_scripts' ) );

			add_filter( 'pmpro_required_billing_fields', array( $this, 'required_billing_fields' ) );
			add_action( 'admin_notices', array( $this, 'refresh_locations_manual' ) );

			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', array( $this, 'include_payment_information_fields' ) );

			add_filter( 'pmpro_after_checkout_preheader', array( $this, 'clear_pmpro_review' ) );

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
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate'
		);

		return $options;
	}

	/**
	 * Description shown for this gateway on the Payment Settings page.
	 *
	 * @since 1.0
	 *
	 * @return string The gateway description.
	 */
	public static function get_description_for_gateway_settings() {
		return esc_html__( 'Accept credit card and debit card payments with Square. Square offers secure online checkout and competitive transaction pricing.', 'pmpro-square' );
	}

	/**
	 * Display this gateway's settings fields on the Payment Settings page.
	 *
	 * Overrides the base gateway method (PMPro 3.5+) so the fields render
	 * natively instead of relying on the legacy pmpro_payment_option_fields
	 * filter and its compatibility shim.
	 *
	 * @since 1.0
	 */
	public static function show_settings_fields() {
		// Build the values array from saved options.
		$values = array();
		foreach ( self::getGatewayOptions() as $key ) {
			$values[ $key ] = get_option( 'pmpro_' . $key );
		}

		$sandbox_locations = get_option( 'pmpro_square_locations_sandbox' );
		$live_locations    = get_option( 'pmpro_square_locations_live' );
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
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&edit_gateway=square&pmpro_square_refresh_locations=sandbox' ), 'pmpro_square_refresh_locations' ) ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' ); ?></a>
									<p class="description"><?php esc_html_e( 'A location is where your transactions occur. Select "Default Location" if you are not sure.', 'pmpro-square' ); ?></p>
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
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-paymentsettings&edit_gateway=square&pmpro_square_refresh_locations=live' ), 'pmpro_square_refresh_locations' ) ); ?>" class="button"><?php esc_html_e( 'Refresh locations', 'pmpro-square' ); ?></a>
									<p class="description"><?php esc_html_e( 'A location is where your transactions occur. Select "Default Location" if you are not sure.', 'pmpro-square' ); ?></p>
								</td>
							</tr>
						<?php } ?>
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
	 * @since 1.0
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
	 * Get the Square access token for an environment.
	 *
	 * @param string $environment Optional. 'live' or 'sandbox'. Defaults to the current environment.
	 * @return string
	 */
	private function get_access_token( $environment = '' ) {
		if ( empty( $environment ) ) {
			$environment = $this->get_environment();
		}
		return ( 'live' === $environment )
			? get_option( 'pmpro_square_live_personal_access_token' )
			: get_option( 'pmpro_square_sandbox_personal_access_token' );
	}

	/**
	 * Make a request to the Square REST API.
	 *
	 * @param string     $method GET|POST|PUT|DELETE.
	 * @param string     $path   e.g. '/v2/payments'.
	 * @param array|null $body   Request body (associative array, JSON-encoded). Null for none.
	 * @return array { success:bool, status:int, data:array, errors:array } ; on transport failure success=false + errors set.
	 */
	public function api_request( $method, $path, $body = null, $environment = '' ) {
		if ( empty( $environment ) ) {
			$environment = $this->get_environment();
		}
		$access_token = $this->get_access_token( $environment );
		if ( empty( $access_token ) ) {
			return array( 'success' => false, 'status' => 0, 'data' => array(), 'errors' => array( array( 'code' => 'NO_CREDENTIALS', 'detail' => __( 'Square is not configured.', 'pmpro-square' ) ) ) );
		}
		$base_url = ( 'live' === $environment ) ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Square-Version' => self::SQUARE_API_VERSION,
				'Authorization'  => 'Bearer ' . $access_token,
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'status' => 0, 'data' => array(), 'errors' => array( array( 'code' => 'HTTP_ERROR', 'detail' => $response->get_error_message() ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) { $data = array(); }
		return array(
			'success' => ( $status >= 200 && $status < 300 ),
			'status'  => $status,
			'data'    => $data,
			'errors'  => isset( $data['errors'] ) ? $data['errors'] : array(),
		);
	}

	/**
	 * Get a readable error message from an api_request() result.
	 *
	 * @since 1.0
	 *
	 * @param array $result The result array from api_request().
	 * @return string
	 */
	private function get_error_message( $result ) {
		if ( ! empty( $result['errors'] ) ) {
			$messages = array();
			foreach ( $result['errors'] as $error ) {
				$code   = isset( $error['code'] ) ? $error['code'] : '';
				$detail = isset( $error['detail'] ) ? $error['detail'] : '';
				$messages[] = $code . ( $detail ? ': ' . $detail : '' );
			}
			return implode( '; ', $messages );
		}
		return __( 'Unknown error', 'pmpro-square' );
	}

	/**
	 * Check if we have the basic necessary data to make an API connection.
	 */
	private function is_ready() {
		$access_token = $this->get_access_token();
		return ! empty( $access_token );
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
	 * Getting the location ID.
	 */
	private function get_location_id( $environment = '' ) {
		if ( empty( $environment ) ) {
			$environment = $this->get_environment();
		}

		$location_id = get_option( 'pmpro_square_' . $environment . '_location_id' );
		if ( $location_id ) {
			return $location_id;
		}

		// No location saved; fall back to the first available location for this environment.
		$locations = get_option( 'pmpro_square_locations_' . $environment );
		if ( ! empty( $locations ) ) {
			return array_key_first( $locations );
		}

		return null;
	}

	/**
	 * Refreshes the list of available locations for an environment on a manual request.
	 */
	public function refresh_locations_manual() {

		if ( empty( $_GET['pmpro_square_refresh_locations'] ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'pmpro_square_refresh_locations' ) ) {
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

		// Refresh locations for every environment that has credentials whenever the Square
		// settings are saved. The settings page shows both the sandbox and live location
		// selectors, so keep both in sync - not just the active environment.
		foreach ( array( 'sandbox', 'live' ) as $environment ) {
			if ( ! empty( $this->get_access_token( $environment ) ) ) {
				$this->refresh_locations( $environment );
			}
		}

	}

	/**
	 * Does API request to get and save the available locations.
	 */
	private function refresh_locations( $environment ) {

		// Gate on the credentials for the environment being refreshed (not the active one).
		if ( empty( $this->get_access_token( $environment ) ) ) {
			return array( 'error' => __( 'Square is not configured for this environment.', 'pmpro-square' ) );
		}

		$result = $this->api_request( 'GET', '/v2/locations', null, $environment );
		if ( ! $result['success'] ) {
			return array( 'error' => $this->get_error_message( $result ) );
		}

		$locations     = array();
		$api_locations = isset( $result['data']['locations'] ) ? $result['data']['locations'] : array();
		if ( ! empty( $api_locations ) ) {
			foreach ( $api_locations as $location ) {
				if ( ! empty( $location['id'] ) ) {
					$locations[ $location['id'] ] = isset( $location['name'] ) ? $location['name'] : '';
				}
			}
		}
		update_option( 'pmpro_square_locations_' . $environment, $locations );
		return array( 'success' => true );

	}

	public function enqueue_scripts() {
		global $gateway, $pmpro_level, $current_user, $pmpro_requirebilling, $pmpro_pages, $pmpro_currency;

		// Don't enqueue Square's scripts if the gateway isn't configured.
		if ( ! $this->is_ready() ) {
			return;
		}

		// Square only processes one-time charges, so the Web Payments SDK always uses the CHARGE intent.
		$initial_payment = floatval( $pmpro_level->initial_payment );
		$intent          = 'CHARGE';

		if ( $this->get_environment() == 'live' ) {
			wp_enqueue_script( 'pmpro-square', 'https://web.squarecdn.com/v1/square.js' );
			$application_id = get_option( 'pmpro_square_live_application_id' );
		} else {
			wp_enqueue_script( 'pmpro-square', 'https://sandbox.web.squarecdn.com/v1/square.js' );
			$application_id = get_option( 'pmpro_square_sandbox_application_id' );
		}
		wp_enqueue_script( 'pmpro-square-processing', PMPRO_SQUARE_URL . 'assets/js/square-processing.js', array( 'jquery' ), PMPRO_SQUARE_VERSION, true );
		wp_localize_script(
			'pmpro-square-processing',
			'pmpro_square_vars',
			array(
				'rest_url'        => get_rest_url(),
				'application_id' => $application_id,
				'location_id'    => $this->get_location_id(),
				'intent'         => $intent,
				'amount'         => $initial_payment,
				'currency'       => $pmpro_currency,
			)
		);
	}

	/**
	 * Remove billing address fields from required fields.
	 *
	 * Square doesn't require a billing address, so PMPro's billing fields are hidden
	 * on checkout (see the pmpro_include_billing_address_fields filter in init()) and
	 * therefore should not be required. Any address that is present on the order is
	 * still sent to Square for AVS in process().
	 */
	public function required_billing_fields( $fields ) {
		// Remove CC and billing address fields.
		$remove = array(
			'bfirstname',
			'blastname',
			'baddress1',
			'bcity',
			'bstate',
			'bzipcode',
			'bcountry',
			'bphone',
			'CardType',
			'AccountNumber',
			'ExpirationMonth',
			'ExpirationYear',
			'CVV',
		);
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
	 * Creates a customer in Square.
	 */
	public function create_customer( $user ) {
		$result = $this->api_request( 'POST', '/v2/customers', array(
			'idempotency_key' => $this->get_idempotency_key(),
			'given_name'      => $user->first_name,
			'family_name'     => $user->last_name,
			'email_address'   => $user->user_email,
		) );

		if ( ! $result['success'] || empty( $result['data']['customer']['id'] ) ) {
			return false;
		}

		$square_customer_id = $result['data']['customer']['id'];
		update_user_meta( $user->ID, 'pmpro_square_customer_id_' . $this->get_environment(), $square_customer_id );
		return $square_customer_id;
	}

	/**
	 * Process checkout.
	 */
	public function process( &$order ) {
		global $pmpro_currency, $current_user;

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		// clean up a couple values
		$order->payment_type = 'Square';
		$order->CardType     = '';
		$order->cardtype     = '';
		$order->status = 'token';

		// Bail if we did not get the payment token generated by JS on the frontend.
		if ( empty( $_POST['square_payment_token'] ) ) {
			$order->error = __( 'Unable to process payment. Please refresh the page and try again, or contact us for help.', 'pmpro-square' );
			return false;
		}

		$square_token = sanitize_text_field( $_POST['square_payment_token'] );

		// SCA: the Web Payments SDK may post a verification token alongside the payment token.
		// It must be passed on the CreatePayment and CreateCard calls or 3DS challenges fail.
		$verification_token = sanitize_text_field( $_POST['square_verification_token'] ?? '' );

		// Square only supports one-time payments. Defensively refuse to process a recurring level -
		// before creating any customer or charge - so a member is never charged a single time for an
		// intended subscription. The checkout guard in checkout_checks_recurring() should already
		// have stopped this earlier.
		if ( pmpro_isLevelRecurring( $order->membership_level ) ) {
			$order->error = __( 'This site uses Square, which only supports one-time payments. Recurring subscriptions cannot be processed.', 'pmpro-square' );
			return false;
		}

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
				if ( empty( $square_customer_id ) ) {
					$order->error = __( 'Error creating customer. Please contact us for help.', 'pmpro-square' );
					return false; // We must have a customer so let's just bail.
				}
			} else {
				// Look for existing customer in Square to confirm it exists.
				$customer_result = $this->api_request( 'GET', '/v2/customers/' . $square_customer_id );

				if ( $customer_result['success'] && ! empty( $customer_result['data']['customer']['id'] ) ) {
					// If our customer IDs are different, let's save the found one to usermeta.
					// This could happen if someone changed to a new Square account and the customer existing in the new one but meta was still saved as old one.
					// Not at all likely, but ya never know - people be doing crazy things.
					$found_id = $customer_result['data']['customer']['id'];
					if ( $square_customer_id != $found_id ) {
						$square_customer_id = $found_id;
						update_user_meta( $user_id, 'pmpro_square_customer_id_' . $this->get_environment(), $square_customer_id );
					}
				} else {
					$square_customer_id = $this->create_customer( $user );
					if ( empty( $square_customer_id ) ) {
						$order->error = __( 'Error creating customer. Please contact us for help.', 'pmpro-square' );
						return false; // We must have a customer so let's just bail.
					}
				}
			}

		} else {
			// No user account was available, bail.
			$order->error = __( 'A user account is required to check out with Square. Please log in or contact us for help.', 'pmpro-square' );
			return false;
		}

		// Setup billing address for API request if present.
		$address_args = array();
		// Pull from POST data as they are separate whereas order combines into single name.
		if ( ! empty( $_POST['bfirstname'] ) ) {
			$address_args['first_name'] = sanitize_text_field( $_POST['bfirstname'] );
		}
		if ( ! empty( $_POST['blastname'] ) ) {
			$address_args['last_name'] = sanitize_text_field( $_POST['blastname'] );
		}
		if ( ! empty( $order->billing->street ) ) {
			$address_args['address_line_1']                  = $order->billing->street;
			$address_args['address_line_2']                  = $order->billing->street2;
			$address_args['locality']                        = $order->billing->city;
			$address_args['administrative_district_level_1'] = $order->billing->state;
			$address_args['postal_code']                     = $order->billing->zip;
			$address_args['country']                         = $order->billing->country;
		}
		// Drop any empty fields before sending the address to Square.
		$address = array();
		foreach ( $address_args as $key => $value ) {
			if ( '' !== $value && null !== $value ) {
				$address[ $key ] = $value;
			}
		}

		// Charge the amount due today, if there is one. $order->total is the amount due now
		// (subtotal + tax) as computed by PMPro core before process() runs, so charging it
		// directly guarantees the charge matches the total displayed to and stored for the member.
		$initial_total = pmpro_round_price( (float) $order->total );
		if ( $initial_total > 0 ) {
			$amount_cents = $this->convert_price_to_minor_units( $initial_total );

			// The payment token is single-use; charge it directly and pass along the SCA
			// verification token so 3DS challenges succeed.
			$payment_id = $this->create_payment( $square_token, $amount_cents, $square_customer_id, $order, $user, $address, $verification_token );
			if ( ! $payment_id ) {
				return false; // Order error already set.
			}
			$order->payment_transaction_id = $payment_id;
		}

		$order->status = 'success';
		return true;

	}

	/**
	 * Charge a one-time payment in Square against the single-use payment token.
	 *
	 * @param string $source_id          The single-use payment token from the checkout form.
	 * @param int    $amount_cents       Amount to charge, in the currency's minor units.
	 * @param string $square_customer_id The Square customer ID.
	 * @param object $order              The PMPro order being processed.
	 * @param object $user               The WordPress user being charged.
	 * @param array  $address            Optional billing address for the request.
	 * @param string $verification_token Optional SCA verification token.
	 * @return string|false The Square payment ID on success, or false on failure (order error is set).
	 */
	private function create_payment( $source_id, $amount_cents, $square_customer_id, $order, $user, $address = array(), $verification_token = '' ) {
		global $pmpro_currency;

		$payment_args = array(
			'source_id'           => $source_id,
			'idempotency_key'     => $this->get_idempotency_key(),
			'amount_money'        => array(
				'amount'   => $amount_cents,
				'currency' => $pmpro_currency,
			),
			'customer_id'         => $square_customer_id,
			'location_id'         => $this->get_location_id(),
			'reference_id'        => $order->code,
			'buyer_email_address' => $user->user_email,
			'note'                => $order->membership_level->name,
		);
		if ( ! empty( $address ) ) {
			$payment_args['billing_address'] = $address;
		}
		if ( ! empty( $verification_token ) ) {
			$payment_args['verification_token'] = $verification_token;
		}

		$result = $this->api_request( 'POST', '/v2/payments', $payment_args );
		if ( ! $result['success'] || empty( $result['data']['payment']['id'] ) ) {
			// Surface the Square error(s) on the order.
			$errors = ! empty( $result['errors'] ) ? $result['errors'] : array();
			if ( empty( $errors ) ) {
				$order->error      = __( 'Error processing payment', 'pmpro-square' ) . ': ' . $this->get_error_message( $result );
				$order->shorterror = $this->get_error_message( $result );
			} else {
				foreach ( $errors as $error ) {
					$code              = isset( $error['code'] ) ? $error['code'] : '';
					$order->error     .= __( 'Error processing payment', 'pmpro-square' ) . ': ' . $code;
					$order->shorterror = $code;
				}
			}
			return false;
		}

		return $result['data']['payment']['id'];
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
	 * Halt checkout when a recurring level is being purchased through Square.
	 *
	 * Square only supports one-time payments, so charging a recurring level would charge a
	 * member a single time for an intended subscription. We stop the checkout before any charge.
	 *
	 * Hooked to pmpro_checkout_checks, which runs before user and order creation. The global
	 * $pmpro_level already reflects any applied discount code (it is set by
	 * pmpro_getLevelAtCheckout() earlier in the checkout preheader), so this also covers
	 * recurring discount codes.
	 *
	 * @since 1.0
	 *
	 * @param bool $continue Whether the checkout should continue.
	 * @return bool
	 */
	public function checkout_checks_recurring( $continue ) {
		global $pmpro_level;

		// Only act when Square is the gateway being used for this checkout.
		if ( pmpro_getGateway() !== 'square' ) {
			return $continue;
		}

		// A previous check already failed; leave its message in place.
		if ( ! $continue ) {
			return $continue;
		}

		if ( ! empty( $pmpro_level ) && pmpro_isLevelRecurring( $pmpro_level ) ) {
			pmpro_setMessage( __( 'This site uses Square, which only supports one-time payments. Recurring subscriptions are not available. Please contact the site administrator.', 'pmpro-square' ), 'pmpro_error' );
			return false;
		}

		return $continue;
	}

	/**
	 * Convert a price in the major currency unit (e.g. dollars) to the minor unit (e.g. cents).
	 *
	 * Uses the currency's configured number of decimal places so zero-decimal currencies such
	 * as JPY use a factor of 1 (no multiplication) rather than the hardcoded x100.
	 *
	 * @since 1.0
	 *
	 * @param float|string $price The price in the major currency unit.
	 * @return int The amount in the currency's minor unit, as an integer.
	 */
	private function convert_price_to_minor_units( $price ) {
		global $pmpro_currency, $pmpro_currencies;

		$decimals = pmpro_get_decimal_place();
		if (
			isset( $pmpro_currencies[ $pmpro_currency ] )
			&& is_array( $pmpro_currencies[ $pmpro_currency ] )
			&& isset( $pmpro_currencies[ $pmpro_currency ]['decimals'] )
		) {
			$decimals = intval( $pmpro_currencies[ $pmpro_currency ]['decimals'] );
		}

		return (int) round( (float) $price * pow( 10, $decimals ) );
	}

}
