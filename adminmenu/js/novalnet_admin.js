/*
 * This script is used for Novalnet plugin admin management process
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
*/

jQuery(document).ready(function() {
        // set the toggle for the payment settings
        var paymentSettings = jQuery('.tab-content').children()[2];
        
        jQuery(paymentSettings).find('[class*=subheading]').append('<i class="fa fa-chevron-circle-down nn_fa"></i>');
    
        jQuery(paymentSettings).find('.mb-3').hover(function () {               
                jQuery(this).css('cursor', 'pointer');
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
        
        if (jQuery('#novalnet_tariffid').val() == undefined) {
            jQuery('input[name=novalnet_tariffid]').attr('id', 'novalnet_tariffid');
        }
        
        // Display the alert box if the public and private key was not configured
        if (jQuery('input[name=novalnet_public_key]').val() == '' && jQuery('input[name=novalnet_private_key]').val() == '') {
            if (jQuery('.nn_mandatory_alert').length == 0) {
                jQuery('.content-header').prepend('<div class="alert alert-info nn_mandatory_alert"><i class="fal fa-info-circle"></i>' + ' '  + jQuery('input[name=nn_lang_notification]').val() + '</div>');
            }
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
        
        if (jQuery('.nn_webhook_button').length == 0) {
            jQuery('#novalnet_webhook_url').parent().parent().after('<div class="row"><div class="ml-auto col-sm-6 col-xl-auto nn_webhook_button"><button name="nn_webhook_configure" id="nn_webhook_configure_button" class="btn btn-primary btn-block">' + jQuery('input[name=nn_webhook_configure]').val() + '</button></div></div>');
        }
        jQuery('#nn_webhook_configure_button').on('click', function() {
            if(jQuery('#novalnet_webhook_url').val() != undefined && jQuery('#novalnet_webhook_url').val() != '') {
                alert(jQuery('input[name=nn_webhook_change]').val());
                configureWebhookUrlAdminPortal();
            } else {
                alert(jQuery('input[name=nn_webhook_invalid]').val());
            }
        });
        
        // Display the webhook test mode activation message
        if (jQuery('.nn_webhook_notify').length == 0) {
            jQuery('#novalnet_webhook_testmode').parent().parent().parent().parent().after(('<div class="nn_webhook_notify">' + jQuery('#nn_webhook_notification').val() + '</div><br>'));
        }
        
        // While click the back button in the Novalnet order history show the order table
        jQuery(document).on('click', '.nn_back_tab', function() {
            jQuery('.nn_order_table').show();
            jQuery('#nn_transaction_info').hide();
            jQuery('.pagination-toolbar').show();
        });
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

function senddata(orderNo) 
{
    var requestParams = { 'order_no' : orderNo, 'nn_request_type' : 'orderDetails' };
    orderDetailsHandler(requestParams);
}

function orderDetailsHandler(requestParams) 
{
    var requestUrl = jQuery('input[id=nn_post_url]').val() ;
        if ('XDomainRequest' in window && window.XDomainRequest !== null) {
            var xdr = new XDomainRequest();
            var query = jQuery.param(requestParams);
            xdr.open('GET', requestUrl + query) ;
            xdr.onload = function () {
                jQuery('.nn_instalment_table').html(result);
                jQuery('.pagination-toolbar').hide();
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
                     jQuery('.nn_order_table').hide();
                     jQuery('#nn_transaction_info').html(result);
                     jQuery('#nn_transaction_info').show();
                     jQuery('.pagination-toolbar').hide();
                }
            });
        }
}
