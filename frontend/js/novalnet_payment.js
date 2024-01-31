/*
 * This script load the payment form on the checkout
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
*/

jQuery(document).ready(function () {
    // Initialize the payment form
    const v13PaymentForm = new NovalnetPaymentForm();
    // Hide the Novalnet payment radio option
    var paymentId = jQuery('#nn_payment_id').val();
    var novalnetPaymentValue = jQuery('#' + paymentId).find('input').val();
    // Hide the uncheck the Novalnet payment if other payment method is selected
    jQuery('input[type="radio"]').on('click',function() {
        if(this.value != novalnetPaymentValue) {
            v13PaymentForm.uncheckPayment();
        }
    });
    var checkPaymentType = null;
    var uncheckPayments = true;
    
    // Set the checkPayment and uncheckPayments
    if(jQuery('#payment' + novalnetPaymentValue).is(':checked')) {
		uncheckPayments = false;
		checkPaymentType = (checkPaymentType) ? checkPaymentType : getCookie('novalnet_selected_payment');
	}
	
    // Set the payment form style
    const paymentFormDisplay = {
        iframe: '#v13PaymentForm',
        initForm : {
            orderInformation : {
                lineItems: jQuery.parseJSON(jQuery("#nn_wallet_data").val())
            },
            setWalletPending: true,
            uncheckPayments: uncheckPayments,
            showButton: false
        }
    };
    if(checkPaymentType) {
		paymentFormDisplay.checkPayment = checkPaymentType;
	}
	
    jQuery("div[id*='_novalnet']").append(jQuery('#v13PaymentForm'));
    
    // Before  Iframe load clicking the submit button active the process again
    jQuery('#v13PaymentForm').on("load", function() {
		 if(jQuery('#payment' + novalnetPaymentValue).is(':checked')) {
			 jQuery('.submit_once').prop('disabled',false);
		}
	});
	
    // Initiatialize the payment form 
    v13PaymentForm.initiate(paymentFormDisplay);
    // Get the payment methods response
    if(jQuery("div[id*='_novalnet']").closest('form').find('.submit_once').length == 1) {
            jQuery('form.checkout-shipping-form').submit(function(e) {
                if(jQuery('#nn_seamless_payment_form_response').length == 0 && jQuery('#'+paymentId+ ' ' + 'input[name="Zahlungsart"]').is(':checked')) {                    
                    // callback for checkout button clicked
                    if(jQuery('#nn_seamless_payment_form_response').length <= 0 || jQuery('#nn_seamless_payment_form_response').val() == '' ) {
                        e.preventDefault();
                    }        
                    try {
						v13PaymentForm.getPayment(
							(data) => {
								if(data.result.status == 'ERROR') {
									jQuery('#novalnet_payment_form_error_alert').text(data.result.message);
									jQuery('#novalnet_payment_form_error_alert').removeClass('d-none'); 
									jQuery('html, body').animate({
										scrollTop: (jQuery('#checkout').offset().top - 160)
										}, 500, function() {
											jQuery('#checkout').prepend(jQuery('#novalnet_payment_form_error_alert'));
									});
									jQuery('.submit_once').prop('disabled',false);
									return false;
								} else {
									jQuery('form.checkout-shipping-form').append('<input type="hidden" name="nn_seamless_payment_form_response" id="nn_seamless_payment_form_response">');
									jQuery('#nn_seamless_payment_form_response').val(btoa(unescape(encodeURIComponent(JSON.stringify(data)))));
									jQuery('form.checkout-shipping-form').submit();
									return true;
								}
							}
						)
					} catch (e) {
							return true;
				   }
				}
            }); 
        }
            
    // Handle wallet payment response
    v13PaymentForm.walletResponse({
        "onProcessCompletion": async (response) =>  {
            if(response.result.status == 'FAILURE' || response.result.status == 'ERROR' ) {
                return {status: 'FAILURE', statusText: 'failure'};
            } else {
				if(jQuery('#nn_seamless_payment_form_response').length == 0) {
					jQuery('form.checkout-shipping-form').append('<input type="hidden" name="nn_seamless_payment_form_response" id="nn_seamless_payment_form_response">');
				}
                jQuery('#nn_seamless_payment_form_response').val(btoa(JSON.stringify(response)));
                jQuery("div[id*='_novalnet']").closest('form').submit();
                return {status: 'SUCCESS', statusText: 'successful'};
            }
        }
    });
    // receive form selected payment action
    v13PaymentForm.selectedPayment(
        (data)=> {
			setCookie(getCookie(), '-1');
			setCookie(data.payment_details.type);
            // Set Novalnet payment as selected
            jQuery('#' + paymentId).find('input').prop('checked', true);
            jQuery('#'+paymentId).find('input[type="radio"]').click();
       }
    )
});

function setCookie(value, expiry)
{
	let expires = new Date();
	expires.setTime(expires.getTime() + (expiry * 24 * 60 * 60 * 1000));
	document.cookie = 'novalnet_selected_payment=' + value + ';expires=' + expires.toUTCString() + ';path=/;SameSite=None;Secure';
}

function getCookie()
{
	var keyValue = document.cookie.match('(^|;) ?novalnet_selected_payment=([^;]*)(;|$)');
    return (keyValue != null && keyValue.length) ? keyValue[2] : null;
}
