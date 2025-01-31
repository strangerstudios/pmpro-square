async function pmpro_square_init_card( payments ) {
	const card = await payments.card();
	await card.attach( '#pmpro-square-card-fields' );
	return card;
}


async function pmpro_square_set_payment_token( token ) {
	console.log( 'Square Token: ' + token );
	jQuery( '#pmpro_form' ).append( '<input type="hidden" name="square_payment_token" value="' + token + '" />' ).submit();
}


/*
// Call this function to send a payment token, buyer name, and other details
// to the project server code so that a payment can be created with
// Payments API
async function pmpro_square_create_payment( token ) {

	const data = {
		'action': 'pmpro_square_init_order',
		'source_id': token,
		'level_id': pmpro_square_vars.level_id,
		'security': pmpro_square_vars.security,
	}

	console.log( 'Square create payment data:', data );

	jQuery.ajax(
		{
			type: 'POST',
			url: pmpro_square_vars.ajax_url,
			data: data,
			success: function( result, textStatus, XMLHttpRequest) {

				if ( result.success ) {
					jQuery( '#pmpro_form' ).append( '<input type="hidden" name="square_payment_id" value="' + result.data.payment_id + '" />' ).submit();
				} else {
					jQuery( '#pmpro-square-status' ).html( '' );
					jQuery( '#pmpro-square-status' ).prepend( '<div style="background: red; color: #FFF; padding: 7px 12px;">' + result.data.reasons + '</div>' );
					return false;
				}

			},
			error: function( MLHttpRequest, textStatus, errorThrown ) {
				alert( 'Sorry, there was an error with the attempt to process with Square' );
			}
		}
	);

}
*/

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

var pmpro_square_card;
document.addEventListener(
	'DOMContentLoaded',
	async function ( e ) {
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
			console.log( 'verify buyer' );
			const verificationDetails = {
				amount: pmpro_square_vars.amount,
				billingContact: { },
				currencyCode: pmpro_square_vars.currency,
				intent: 'CHARGE',
			};
			
			const verificationResults = await payments.verifyBuyer(
				token,
				verificationDetails,
			);
			console.log( 'Verification results:', verificationResults );
			return verificationResults.token;
		}
		
		async function pmpro_square_handle_submission( event, paymentMethod ) {
			event.preventDefault();
			console.log( 'handle submission' );
			try {
				const pmpro_square_token      = await pmpro_square_tokenize( paymentMethod );
				const pmpro_square_verification_token = await pmpro_square_verify_buyer( pmpro_square_payments, pmpro_square_token );
				await pmpro_square_set_payment_token( pmpro_square_token );
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
