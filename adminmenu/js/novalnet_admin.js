jQuery(document).ready(function() {
        // Select all credit card types
        
        if (jQuery('#novalnet_cc_accepted_card_types').val() == '') {
            jQuery('#novalnet_cc_accepted_card_types option').each(function()  {
                var optionVal = jQuery(this).val();
                jQuery('#novalnet_cc_accepted_card_types option[value=' + optionVal + ']').attr('selected', true);
                
            });
        }
    
        // Select default instalment cycles
        jQuery.each(['#novalnet_instalment_invoice_cycles', '#novalnet_instalment_sepa_cycles'], function (index, element) {
            if (jQuery('#nn_shop_lang').val() != 'de-DE') {
            jQuery(element + ' ' + 'option').each(function()  {
                    jQuery(this).text(jQuery(this).html().replace(/\bZyklen\b/g, 'Cycle'));
                });
            }
            
            if (jQuery(element).val() == '') {
                jQuery(element + ' ' + 'option').each(function()  {
                    if (jQuery(this).val() <= 12) {
                            jQuery(element + ' ' + 'option[value=' + jQuery(this).val() + ']').attr('selected', true);
                        }
                });
            }
        });
        
        // Alert if the multiple selection doesn' have anyne selection
        jQuery.each(['#novalnet_cc_accepted_card_types', '#novalnet_instalment_invoice_cycles', '#novalnet_instalment_sepa_cycles'], function (index, element) {
            jQuery(element).on('change', function () {
                if (!jQuery(element + ' ' + "option:selected").length) {
                    handleErrorElement(jQuery(element), jQuery('#nn_multiselect_text').val());
                }
            });
        });
            
        // set the toggle for the payment settings
        var paymentSettings = jQuery('.tab-content').children()[2];
        
        
        jQuery(paymentSettings).find('[class*=subheading]').append('<i class="fa fa-chevron-circle-down nn_fa"></i>');
    
        jQuery(paymentSettings).find('.mb-3').hover(function () {               
                jQuery(this).css('cursor', 'pointer');
        });
        
        // Show and hide the authorization amount field value
        jQuery.each(['cc', 'sepa', 'invoice', 'paypal', 'guaranteed_invoice', 'guaranteed_sepa', 'instalment_invoice', 'instalment_sepa'],function(index, value) {
            if(jQuery(paymentSettings).find('#novalnet_'+value+'_payment_action').val() == 0) {
                    jQuery(paymentSettings).find('#novalnet_'+value+'_manual_check_limit').parent().parent().hide(); 
            }
            jQuery(paymentSettings).find('#novalnet_'+value+'_payment_action').on('change',function(event){
                if(jQuery(paymentSettings).find('#novalnet_'+value+'_payment_action').val() == 0) {
                    jQuery(paymentSettings).find('#novalnet_'+value+'_manual_check_limit').parent().parent().hide();
                } else {
                    jQuery(paymentSettings).find('#novalnet_'+value+'_manual_check_limit').parent().parent().show();
                }
            });     
        });
        
        // Set the error class if the condition not met
        jQuery('#novalnet_sepa_due_date, #novalnet_invoice_due_date, #novalnet_prepayment_due_date, #novalnet_guaranteed_invoice_min_amount, #novalnet_guaranteed_sepa_min_amount, #novalnet_instalment_invoice_min_amount, #novalnet_instalment_sepa_min_amount').parent().on('change', function() {
            if (jQuery(this).hasClass('set_error')) jQuery(this).removeClass('set_error');
        });
        
        
        
        
            
        // Payment settings toggle
        jQuery('.nn_fa').each(function(){
            jQuery(this).parent().addClass('nn-toggle-heading');
            jQuery(this).parent().next().next().addClass('nn-toggle-content');
        });
        jQuery('.nn-toggle-content').hide();
        
        jQuery('.nn-toggle-heading').on('click',function(){
            jQuery(this).next().next().toggle(700);
            if( jQuery(this).children('i').hasClass('fa-chevron-circle-down') ) {
                jQuery(this).children('i').addClass('fa-chevron-circle-up').removeClass('fa-chevron-circle-down');
            } else {
                jQuery(this).children('i').addClass('fa-chevron-circle-down').removeClass('fa-chevron-circle-up');
            }
        });
        
        // Hide the client key field
        jQuery('input[id=novalnet_client_key]').parent().parent('.form-group').addClass('hide_client_key');
        jQuery('.hide_client_key').hide();
        
        if (jQuery('#novalnet_tariffid').val() == undefined) {
            jQuery('input[name=novalnet_tariffid]').attr('id', 'novalnet_tariffid');
        }
        
        // Display the alert box if the public and private key was not configured
        if (jQuery('input[name=novalnet_public_key]').val() == '' && jQuery('input[name=novalnet_private_key]').val() == '') {
            jQuery('.content-header').prepend('<div class="alert alert-info"><i class="fal fa-info-circle"></i>' + ' '  + jQuery('input[name=nn_lang_notification]').val() + '</div>');
            
            
        }
    
        // Autofill the merchant details
        if (jQuery('input[name=novalnet_public_key]').val() != undefined && jQuery('input[name=novalnet_public_key]').val() != '') {
              fillMerchantConfiguration();
        } else if (jQuery('input[name=novalnet_public_key]').val() == '') {
           jQuery('#novalnet_tariffid').val('');
        }
    
        jQuery('input[name=novalnet_public_key], input[name=novalnet_private_key]' ).on('change', function () {
            if (jQuery('input[name=novalnet_public_key]').val() != '' &&  jQuery('input[name=novalnet_private_key]').val() != '') {
                fillMerchantConfiguration();
            } else {
                jQuery('#novalnet_tariffid').val('');
            }
        });
        
        // Set the webhook URL
        jQuery('input[name=novalnet_webhook_url]').val(jQuery('#nn_webhook_url').val());
        
        jQuery('#novalnet_webhook_url').parent().parent().after('<div class="row"><div class="ml-auto col-sm-6 col-xl-auto nn_webhook_button"><button name="nn_webhook_configure" id="nn_webhook_configure_button" class="btn btn-primary btn-block">' + jQuery('input[name=nn_webhook_configure]').val() + '</button></div></div>');
        
        jQuery('#nn_webhook_configure_button').on('click', function() {
            if(jQuery('#novalnet_webhook_url').val() != undefined && jQuery('#novalnet_webhook_url').val() != '') {
                alert(jQuery('input[name=nn_webhook_change]').val());
                configureWebhookUrlAdminPortal();
            } else {
                alert(jQuery('input[name=nn_webhook_invalid]').val());
            }
        });
        
        // Backend payment configuration validation
        jQuery('button[name=speichern]').on('click', function(event){
            
            // SEPA payment due date validation
            jQuery.each(['#novalnet_sepa_due_date', '#novalnet_guaranteed_sepa_due_date', '#novalnet_instalment_sepa_due_date'], function (index, element) {
                if (jQuery.trim(jQuery(element).val()) != '' && (isNaN(jQuery(element).val()) || jQuery(element).val() < 2 || jQuery(element).val() > 14)) {
                    handleErrorElement(jQuery(element), jQuery('#nn_invalid_due_date').val());
                }
            });
            
                // INVOICE and Prepayment due date validation
                if (jQuery.trim(jQuery('#novalnet_invoice_due_date').val()) != '' && (isNaN(jQuery('#novalnet_invoice_due_date').val()) || jQuery('#novalnet_invoice_due_date').val() < 7 )) {
                    handleErrorElement(jQuery('#novalnet_invoice_due_date'), jQuery('#nn_invalid_due_date').val());
                }
                
                if (jQuery.trim(jQuery('#novalnet_prepayment_due_date').val()) != '' && (isNaN(jQuery('#novalnet_prepayment_due_date').val()) || jQuery('#novalnet_prepayment_due_date').val() < 7 || jQuery('#novalnet_prepayment_due_date').val() > 28)) {
                    handleErrorElement(jQuery('#novalnet_prepayment_due_date'), jQuery('#nn_invalid_due_date').val());
                }

            
            // Minimum Instalment amount validation
            jQuery.each(['#novalnet_instalment_invoice_min_amount', '#novalnet_instalment_sepa_min_amount'], function (index, element) {
                if (jQuery.trim(jQuery(element).val()) != '' && (isNaN(jQuery(element).val()) || jQuery(element).val() < 1998)) {
                    handleErrorElement(jQuery(element), jQuery('#nn_instalment_min_amount_error_text').val());
                }
            });
            
            // Minimum guarantee payment amount validation
            jQuery.each(['#novalnet_guaranteed_invoice_min_amount', '#novalnet_guaranteed_sepa_min_amount'], function (index, element) {
                if (jQuery.trim(jQuery(element).val()) != '' && (isNaN(jQuery(element).val()) || jQuery(element).val() < 999)) {
                    
                    handleErrorElement(jQuery(element), jQuery('#nn_guarantee_min_amount_error_text').val());
                }
            });
            
            jQuery.each(['#novalnet_cc_accepted_card_types', '#novalnet_instalment_invoice_cycles', '#novalnet_instalment_sepa_cycles'], function (index, element) {
                    if (jQuery(element).val() == '') {
                        handleErrorElement(jQuery(element), jQuery('#nn_multiselect_text').val());
                    }
            });
        });
        
        // Display the payment messages below the payment type
        jQuery.each(['#novalnet_instalment_invoice_enablemode', '#novalnet_instalment_sepa_enablemode'], function (index, element) {
            jQuery(element).closest('.nn-toggle-content').prepend(('<div class="form-group nn_additional_content"><span class="nn_content">' + jQuery('#nn_instalment_payment_conditions').val() + '</span></div>'));
        });
        
        jQuery.each(['#novalnet_guaranteed_invoice_enablemode', '#novalnet_guaranteed_sepa_enablemode'], function (index, element) {
            jQuery(element).closest('.nn-toggle-content').prepend(('<div class="form-group nn_additional_content"><span class="nn_content">' + jQuery('#nn_guarantee_payment_conditions').val() + '</span></div>'));
        });
        
        jQuery('#novalnet_paypal_enablemode').closest('.nn-toggle-content').prepend(('<div class="form-group nn_additional_content"><span class="nn_content">' + jQuery('#nn_paypal_api_configure').val() + '</span></div>'));
        
        jQuery('#novalnet_paypal_one_click_shopping').parent().parent().parent().parent().after('<span class="nn_paypal_notify" style="display:none;">' + jQuery('#nn_paypal_one_click_notification').val() + '</span><br>');
        
        jQuery('#novalnet_webhook_testmode').parent().parent().parent().parent().after(('<div class="nn_webhook_notify">' + jQuery('#nn_webhook_notification').val() + '</div><br>'));
        
        
        jQuery('#novalnet_paypal_one_click_shopping').on('change', function(){
            jQuery('.nn_paypal_notify').css('display', 'none');
            if (this.value != '') jQuery('.nn_paypal_notify').css('display', 'block');
        });

        if (jQuery('#novalnet_paypal_one_click_shopping').val() != '') {
            jQuery('.nn_paypal_notify').css('display', 'block');
        }
    
});

function fillMerchantConfiguration() {
    var autoconfigurationRequestParams = { 'nn_public_key' : jQuery('input[name=novalnet_public_key]').val(), 'nn_private_key' : jQuery('input[name=novalnet_private_key]').val(), 'nn_request_type' : 'autofill' };
    transactionRequestHandler(autoconfigurationRequestParams);
}

function transactionRequestHandler(requestParams)
{
    requestParams = typeof(requestParams !== 'undefined') ? requestParams : '';
    
    var requestUrl = jQuery('input[id=nn_post_url]').val() ;
        if ('XDomainRequest' in window && window.XDomainRequest !== null) {
            var xdr = new XDomainRequest();
            var query = jQuery.param(requestParams);
            xdr.open('GET', requestUrl + query) ;
            xdr.onload = function () {
                autofillMerchantDetails(this.responseText);
            };
            xdr.onerror = function () {
                _result = false;
            };
            xdr.send();
        } else {
            jQuery.ajax({
                url        :  requestUrl,
                type       : 'post',
                dataType   : 'html',
                data       :  requestParams,
                global     :  false,
                async      :  false,
                success    :  function (result) {
                    autofillMerchantDetails(result);
                }
            });
        }
}

function autofillMerchantDetails(result)
{
     var fillParams = jQuery.parseJSON(result);

    if (fillParams.result.status != 'SUCCESS') {
        jQuery('input[name="novalnet_public_key"],input[name="novalnet_private_key"]').val('');
        jQuery('.content-header').prepend('<div class="alert alert-danger align-items-center"><i class="fal fa-info-circle mr-2"></i>' + fillParams.result.status_text + '</div>');
        jQuery('#novalnet_tariffid').val('');
        return false;
    }
    
    var tariffKeys = Object.keys(fillParams.merchant.tariff);
    var saved_tariff_id = jQuery('#novalnet_tariffid').val();
    var tariff_id;

    try {
        var select_text = decodeURIComponent(escape('Auswählen'));
    } catch(e) {
        var select_text = 'Auswählen';
    }

    jQuery('#novalnet_tariffid').replaceWith('<select id="novalnet_tariffid" class="form-control combo" name="novalnet_tariffid"><option value="" disabled>'+select_text+'</option></select>');

    jQuery('#novalnet_tariffid').find('option').remove();
    
    for (var i = 0; i < tariffKeys.length; i++) 
    {
        if (tariffKeys[i] !== undefined) {
            jQuery('#novalnet_tariffid').append(
                jQuery(
                    '<option>', {
                        value: jQuery.trim(tariffKeys[i])+'-'+  jQuery.trim(fillParams.merchant.tariff[tariffKeys[i]].type),
                        text : jQuery.trim(fillParams.merchant.tariff[tariffKeys[i]].name)
                    }
                )
            );
        }
        if (saved_tariff_id == jQuery.trim(tariffKeys[i])+'-'+  jQuery.trim(fillParams.merchant.tariff[tariffKeys[i]].type)) {
            jQuery('#novalnet_tariffid').val(jQuery.trim(tariffKeys[i])+'-'+  jQuery.trim(fillParams.merchant.tariff[tariffKeys[i]].type));
        }
    }
    jQuery('#novalnet_client_key').val(fillParams.merchant.client_key);
}


function configureWebhookUrlAdminPortal()
{
    var novalnetWebhookParams = { 'nn_public_key' : jQuery('input[name=novalnet_public_key]').val(), 'nn_private_key' : jQuery('input[name=novalnet_private_key]').val(), 'nn_webhook_url' : jQuery('input[name=novalnet_webhook_url]').val(), 'nn_request_type' : 'configureWebhook' };
    
    webhookRequestParams = typeof(novalnetWebhookParams !== 'undefined') ? novalnetWebhookParams : '';
    
    var requestUrl = jQuery('input[id=nn_post_url]').val();
        if ('XDomainRequest' in window && window.XDomainRequest !== null) {
            var xdr = new XDomainRequest();
            var query = jQuery.param(webhookRequestParams);
            xdr.open('GET', requestUrl + query) ;
            xdr.onload = function () {
                updateWebhookStatus(result);
            };
            xdr.onerror = function () {
                _result = false;
            };
            xdr.send();
        } else {
            jQuery.ajax({
                url        :  requestUrl,
                type       : 'post',
                dataType   : 'html',
                data       :  webhookRequestParams,
                global     :  false,
                async      :  false,
                success    :  function (result) {
                    updateWebhookStatus(result);
                }
            });
        }
}

function updateWebhookStatus(result) 
{
    var webhookResult = jQuery.parseJSON(result);
    
    if(webhookResult.result.status == 'SUCCESS') {
        alert(jQuery('input[name=nn_webhook_success]').val());
    } else {
        alert(webhookResult.result.status_text);
    }
}

function handleErrorElement(element, errorText, setclass) {
    event.preventDefault();
    jQuery(element).css('border', '2px solid red');
    alert(errorText);
    jQuery('html, body').animate({
        scrollTop: (element.offset().top - 160)
        }, 500, function() {

        if (setclass !== false) {
            jQuery(element).parent().addClass('set_error');
        }
        
        if(jQuery('.set_error').length && jQuery('.set_error').parent().parent().css('display') == 'none') {    
                jQuery('.set_error').parent().parent().parent().find('.subheading1').click();
        }
            
        if (jQuery(element).parent().closest('div').css('display') == 'none') {
            jQuery(this).css('display','block');
        }
    });
}
