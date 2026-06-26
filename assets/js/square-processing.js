async function pmpro_square_init_card( payments ) {
	const card = await payments.card();
	await card.attach( '#pmpro-square-card-fields' );
	return card;
}


async function pmpro_square_set_payment_token( token, verificationToken ) {
	const form = jQuery( '#pmpro_form' );
	form.append( '<input type="hidden" name="square_payment_token" value="' + token + '" />' );
	// Send the SCA verification token so the server can apply it to the payment / card on file.
	if ( verificationToken ) {
		form.append( '<input type="hidden" name="square_verification_token" value="' + verificationToken + '" />' );
	}
	form.submit();
}

 // This function tokenizes a payment method.
 // The ‘error’ thrown from this async function denotes a failed tokenization,
 // which is due to buyer error (such as an expired card). It is up to the
 // developer to handle the error and provide the buyer the chance to fix
 // their mistakes.
 async function pmpro_square_tokenize( paymentMethod ) {
	 const tokenResult = await paymentMethod.tokenize();
	if ( tokenResult.status === 'OK' ) {
		return tokenResult.token;
	} else {
		let errorMessage = `Tokenization failed - status: ${tokenResult.status}`;
		if ( tokenResult.errors ) {
			errorMessage += ` and errors: ${JSON.stringify(
				tokenResult.errors
			)}`;
		}
		throw new Error( errorMessage );
	}
 }

 // Helper method for displaying the Payment Status on the screen.
 // status is either SUCCESS or FAILURE;
 function pmpro_square_display_payment_results( status ) {
    const statusContainer = document.getElementById(
		'pmpro-square-status'
    );
	 if ( status === 'SUCCESS' ) {
		 statusContainer.classList.remove( 'is-failure' );
		 statusContainer.classList.add( 'is-success' );
	 } else {
		 statusContainer.classList.remove( 'is-success' );
		 statusContainer.classList.add( 'is-failure' );
	 }
	 statusContainer.style.visibility = 'visible';
 }

// Mirrors the PMPro Stripe integration: track whether billing is required for this
// checkout. PMPro seeds this from the server and the applydiscountcode service updates it
// when a code is applied (e.g. a code that zeroes the price sets it to false), so reading
// it at submit time also covers the case where a discount code makes the order free.
var pmpro_require_billing;

var pmpro_square_card;
document.addEventListener(
	'DOMContentLoaded',
	async function ( e ) {
		if ( typeof pmpro_require_billing === 'undefined' ) {
			pmpro_require_billing = pmpro_square_vars.pmpro_require_billing;
		}

		if ( ! window.Square ) {
			throw new Error( 'Square.js failed to load properly' );
		}

		if ( pmpro_square_card ) {
			return;
		}

		const pmpro_square_payments = window.Square.payments( pmpro_square_vars.application_id, pmpro_square_vars.location_id );
		try {
			pmpro_square_card = await pmpro_square_init_card( pmpro_square_payments );
		} catch (e) {
			return;
		}

		// Required in SCA Mandated Regions: Learn more at https://developer.squareup.com/docs/sca-overview
		async function pmpro_square_verify_buyer(payments, token) {
			const verificationDetails = {
				amount: pmpro_square_vars.amount,
				billingContact: { },
				currencyCode: pmpro_square_vars.currency,
				intent: pmpro_square_vars.intent,
			};
			
			const verificationResults = await payments.verifyBuyer(
				token,
				verificationDetails,
			);
			return verificationResults.token;
		}
		
		async function pmpro_square_handle_submission( event, paymentMethod ) {
			// When billing is not required - a free level, or a discount code that zeroed the
			// price - there is no card to tokenize. Let the form submit normally so PMPro
			// completes the checkout through its free path. This mirrors the PMPro Stripe
			// integration, which gates on the same global that the applydiscountcode service
			// keeps in sync, so it stays correct even when a code is applied after page load.
			if ( ! pmpro_require_billing ) {
				return;
			}
			event.preventDefault();
			try {
				const pmpro_square_token      = await pmpro_square_tokenize( paymentMethod );
				const pmpro_square_verification_token = await pmpro_square_verify_buyer( pmpro_square_payments, pmpro_square_token );
				await pmpro_square_set_payment_token( pmpro_square_token, pmpro_square_verification_token );
			} catch (e) {
				pmpro_square_display_payment_results( 'FAILURE' );
			}

		}

		const cardButton = document.getElementById(
			'pmpro_btn-submit'
		);
		cardButton.addEventListener(
			'click',
			async function (event) {
				await pmpro_square_handle_submission( event, pmpro_square_card );
			}
		);

	}
);

// Look for price change and updated payment info for getting token accordingly
jQuery(".pmpro_alter_price").change(function(){
	jQuery.ajax({
		url: pmpro_square_vars.rest_url + 'pmpro/v1/checkout_level',
		dataType: 'json',
		data: pmpro_getCheckoutFormDataForCheckoutLevels(),
		success: function(data) {
			if ( data.hasOwnProperty('initial_payment') ) {
				pmpro_square_vars.amount = data.initial_payment;
			}
		}
	});
});
