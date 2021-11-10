/*
 * Novalnet payment plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Novalnet End User License Agreement
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @author      Novalnet AG
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Novalnet payment form processing script
*/

jQuery(document).ready(function () {
        
    var payment_name = jQuery('#nn_payment').val();
    
    if (payment_name == 'novalnet_cc') {    
    
        var ccCustomFields = jQuery('#nn_cc_formfields').val() != '' ? jQuery.parseJSON(jQuery('#nn_cc_formfields').val()) : null;
        var ccFormDetails= jQuery('#nn_cc_formdetails').val() != '' ? jQuery.parseJSON(jQuery('#nn_cc_formdetails').val()) : null;
        
        // Setting the Client key which is required to load the Credit Card form
        NovalnetUtility.setClientKey((ccFormDetails.client_key !== undefined) ? ccFormDetails.client_key : '');

        var requestData = {
            'callback': {
                on_success: function (result) {
                    jQuery('#nn_cc_panhash').val(result['hash']);
                    jQuery('#nn_cc_uniqueid').val(result['unique_id']);
                    jQuery('#nn_cc_3d_redirect').val(result['do_redirect']);
                    jQuery('#novalnet_cc_iframe').closest('form').submit();
                    return true;
                },
                on_error: function (result) {
                    if ( undefined !== result['error_message'] ) {
                        jQuery('#novalnet_cc_error_alert').text(result['error_message']);
                        jQuery('#novalnet_cc_error_alert').removeClass('d-none');
                        jQuery('.submit_once').removeAttr('disabled');               
                        return false;
                    }
                },
          
                // Called in case the challenge window Overlay (for 3ds2.0) displays
                on_show_overlay:  function (result) {
                  jQuery('#novalnet_cc_iframe').addClass('novalnet_cc_overlay');
                },
                
                 // Called in case the Challenge window Overlay (for 3ds2.0) hided
                on_hide_overlay:  function (result) {
                  jQuery('#novalnet_cc_iframe').removeClass('novalnet_cc_overlay');
                }
            },

            // You can customize your Iframe container style, text etc.
            'iframe': {
                // Passing the Iframe Id
                id: "novalnet_cc_iframe",
                
                // Display the inline form if the values is set as 1
                inline: (ccFormDetails.inline_form !== undefined) ? ccFormDetails.inline_form : '0',
         
                // Adjust the creditcard style and text 
                style: {
                    container: (ccCustomFields.novalnet_cc_form_css !== undefined) ? ccCustomFields.novalnet_cc_form_css : '',
                    input: (ccCustomFields.novalnet_cc_form_input !== undefined) ? ccCustomFields.novalnet_cc_form_input : '' ,
                    label: (ccCustomFields.novalnet_cc_form_label !== undefined) ? ccCustomFields.novalnet_cc_form_label : '' ,
                },
          
                text: {
                    lang : (ccFormDetails.lang !== undefined) ? ccFormDetails.lang : 'en',
                    error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : '',
                    card_holder : {
                        label: (ccCustomFields.credit_card_name !== undefined) ? ccCustomFields.credit_card_name : '',
                        place_holder: (ccCustomFields.credit_card_name_input !== undefined) ? ccCustomFields.credit_card_name_input : '',
                        error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : ''
                    },
                    card_number : {
                        label: (ccCustomFields.credit_card_number !== undefined) ? ccCustomFields.credit_card_number : '',
                        place_holder: (ccCustomFields.credit_card_number_input !== undefined) ? ccCustomFields.credit_card_number_input : '',
                        error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : ''
                    },
                    expiry_date : {
                        label: (ccCustomFields.credit_card_date !== undefined) ? ccCustomFields.credit_card_date : '',
                        place_holder: (ccCustomFields.credit_card_date_input !== undefined) ? ccCustomFields.credit_card_date_input : '',
                        error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : ''
                    },
                    cvc : {
                        label: (ccCustomFields.credit_card_cvc !== undefined) ? ccCustomFields.credit_card_cvc : '',
                        place_holder: (ccCustomFields.credit_card_cvc_input !== undefined) ? ccCustomFields.credit_card_cvc_input : '',
                        error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : ''
                    }
                  }
                },

                // Add Customer data
                customer: {
                    first_name: (ccFormDetails.first_name !== undefined) ? ccFormDetails.first_name : '',
                    last_name: (ccFormDetails.last_name !== undefined) ? ccFormDetails.last_name : ccFormDetails.first_name,
                    email: (ccFormDetails.email !== undefined) ? ccFormDetails.email : '',
                    billing: {
                        street: (ccFormDetails.street !== undefined) ? ccFormDetails.street : '',
                        city: (ccFormDetails.city !== undefined) ? ccFormDetails.city : '',
                        zip: (ccFormDetails.zip !== undefined) ? ccFormDetails.zip : '',
                        country_code: (ccFormDetails.country_code !== undefined) ? ccFormDetails.country_code : ''
                    },
                    shipping: {
                        same_as_billing: (ccFormDetails.same_as_billing !== undefined) ? ccFormDetails.same_as_billing : 0,
                    },
                },
                
                // Add transaction data
                transaction: {
                  amount: (ccFormDetails.amount !== undefined) ? ccFormDetails.amount : '',
                  currency: (ccFormDetails.currency !== undefined) ? ccFormDetails.currency : '',
                  test_mode: (ccFormDetails.test_mode !== undefined) ? ccFormDetails.test_mode : '0',
                  enforce_3d: (ccFormDetails.enforce_3d !== undefined) ? ccFormDetails.enforce_3d : '0',
                }
            };
    }   
            
    // Save card/account details process
    if (jQuery("#nn_toggle_form").length <= 0) {
        jQuery("#nn_load_new_form").show();
    } else {
        jQuery("#nn_load_new_form").hide();
    }               
    
    if (jQuery("input[name='nn_radio_option']").length > 0) { 
        var token = jQuery("input[name='nn_radio_option']:first").val(); 
        if (token){
            jQuery('#nn_customer_selected_token').val(token);
        } else {
            jQuery('#nn_customer_selected_token').val('');
        }
    }

    jQuery("input[name='nn_radio_option']").on('click', function () {
        var tokenValue = jQuery(this).val();
        if (tokenValue){
            jQuery('#nn_customer_selected_token').val(tokenValue);
        } else {
            jQuery('#nn_customer_selected_token').val('');
        }
        
        if (jQuery(this).attr('id') == 'nn_toggle_form') {
            jQuery("#nn_load_new_form").show();
            jQuery('#nn_customer_selected_token').val('');                
        } else {
            jQuery("#nn_load_new_form").hide();                
        }
    });
    
    jQuery("input[name='nn_radio_option']:first").attr("checked","checked");
        
    // For Direct Debit SEPA payment form process
    if (jQuery.inArray( payment_name, ['novalnet_sepa', 'novalnet_guaranteed_sepa', 'novalnet_instalment_sepa']) > -1) {
        
        var paymentFormId = jQuery('#nn_payment').closest('form').attr('id');
            jQuery('#'+paymentFormId).submit(function () {
                if (jQuery('#nn_load_new_form').css('display') !== 'none' && (jQuery('#nn_sepa_account_no').val() == '')) { 
                    jQuery('#novalnet_sepa_error_alert').text(jQuery('#nn_account_invalid').val());
                    jQuery('#novalnet_sepa_error_alert').removeClass('d-none'); 
                    jQuery('.submit_once').removeAttr('disabled');                  
                    return false;
                }           
            }
        );
    }
    
    // For Credit Card payment form process
    if (payment_name == 'novalnet_cc') {            
        NovalnetUtility.createCreditCardForm(requestData);            
        var paymentFormId = jQuery('#nn_payment').closest('form').attr('id');
        jQuery('#'+ paymentFormId).on('submit', function (evt) {
            if (jQuery('#nn_cc_panhash').val() == '' && jQuery('#nn_load_new_form').css('display') !== 'none') {                     
                NovalnetUtility.getPanHash();
                evt.preventDefault();
                evt.stopImmediatePropagation();
            }
        });
    }
                
    // For Instalment payment methods, 
    if (jQuery('#nn_instalment_cycle').length > 0) {
        jQuery('#nn_instalment_cycle').on('change',function() {             
            var cycleInformation = '';              
            for (instalmentCycle = 1; instalmentCycle <= jQuery(this).val(); instalmentCycle++) {
                if(instalmentCycle != jQuery(this).val())
                {
                    cycleInformation += '<tr><td>' + instalmentCycle + '</td><td>'+ jQuery(this).find(':selected').attr('data-amount') +'</td></tr>';
                } else {
                    var lastCycleAmount = (jQuery('#nn_order_amount').val() - (jQuery(this).find(':selected').attr('data-cycle-amount') * (jQuery(this).val() - 1)));
                    
                    cycleInformation += '<tr><td>' + instalmentCycle + '</td><td>'+ formatMoney(lastCycleAmount) + ' '+ jQuery('#nn_order_currency').val()+'</td></tr>';
                }
            }                           
            jQuery('#nn_instalment_cycle_information').html(cycleInformation);
        }).change();
    }    

    // Check if the provided company is valid, if not ask for the birthdate
    if (jQuery('#nn_company').val() != '') {
        var companyValid = NovalnetUtility.isValidCompanyName(jQuery('#nn_company').val());     
        if (companyValid) {                
            jQuery('#nn_show_dob').hide();
        } else {               
            jQuery('#nn_show_dob').show();
        }
    }
    
    // validate the birthdate field
     if (jQuery('#nn_show_dob').css('display') !== 'none' && ((jQuery.inArray( payment_name, ['novalnet_guaranteed_invoice', 'novalnet_guaranteed_sepa', 'novalnet_instalment_invoice', 'novalnet_instalment_sepa']) > -1) )) {
        var paymentFormId = jQuery('#nn_payment').closest('form').attr('id');
        
        jQuery('#'+ paymentFormId).on('submit', function () {
            var birthDay = jQuery('#nn_dob').val().split('-');
            var dob = new Date();
            dob.setFullYear(birthDay[0], birthDay[1]-1, birthDay[2]);
            var currentDate = new Date();
            currentDate.setFullYear(currentDate.getFullYear() - 18);
            if ((currentDate - dob) < 0) {
                jQuery('#novalnet_dob_error_alert').text(jQuery('#novalnet_dob_invalid').val());
                jQuery('#novalnet_dob_error_alert').removeClass('d-none'); 
                jQuery('.submit_once').removeAttr('disabled');
                return false;
            }
            else if (jQuery('#nn_dob').val() == '') {
                jQuery('#novalnet_dob_error_alert').text(jQuery('#novalnet_dob_empty').val());
                jQuery('#novalnet_dob_error_alert').removeClass('d-none'); 
                jQuery('.submit_once').removeAttr('disabled');
                return false;
            }
        });
    }       
});

// Building the request to remove the saved token 
function removeSavedDetails(tid)
{
   var savedDetailsToRemove = {'tid': tid, 'nn_request_type':'remove', 'payment_name':jQuery('#nn_payment').val()};   
   removeSavedDetailsRequestHandler(savedDetailsToRemove);
}

// Remove the save card details based on the customer input
function removeSavedDetailsRequestHandler(savedDetailsToRemove)
{
    if (confirm(jQuery('#remove_saved_payment_detail').val())) {
        var postUrl = window.location.href;
        if ('XDomainRequest' in window && window.XDomainRequest !== null) {
            var xdr = new XDomainRequest(); // Use Microsoft XDR
            var savedDetailsToRemove = jQuery.param(savedDetailsToRemove);
            xdr.open('POST', postUrl);
            xdr.onload = function (result) {
                jQuery('#remove_'+savedDetailsToRemove['token']).remove();
                alert(jQuery('#alert_text_payment_detail_removal').val());
                location.reload();
            };
            xdr.onerror = function () {
                _result = false;
            };
            xdr.send(savedDetailsToRemove);
        } else {
            jQuery.ajax({
                url      : postUrl,
                type     : 'post',
                dataType : 'html',
                data     : savedDetailsToRemove,
                success  : function (result) {                    
                    jQuery('#novalnet_removal_error_alert').text(jQuery('#alert_text_payment_detail_removal').val());
                    jQuery('#novalnet_removal_error_alert').removeClass('d-none');
                    location.reload();
                }
            });
        }
    }
}

// Formatting the amount

function formatMoney(amount, decimalCount = 2, decimal = ",", thousands = ".") {
  try {
    decimalCount = Math.abs(decimalCount);
    decimalCount = isNaN(decimalCount) ? 2 : decimalCount;

    const negativeSign = amount < 0 ? "-" : "";

    let i = parseInt(amount = Math.abs(Number(amount) || 0).toFixed(decimalCount)).toString();
    let j = (i.length > 3) ? i.length % 3 : 0;

    return negativeSign + (j ? i.substr(0, j) + thousands : '') + i.substr(j).replace(/(\d{3})(?=\d)/g, "jQuery1" + thousands) + (decimalCount ? decimal + Math.abs(amount - i).toFixed(decimalCount).slice(2) : "");
  } catch (e) {
    console.log(e)
  }
}
