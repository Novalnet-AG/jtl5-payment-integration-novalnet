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
 * Novalnet wallet payment helper script
*/

var processedProduct = {};
// Transaction Information
function transactionInfo(paymentName) {
    var transactionDetails = {
        amount          : (jQuery('#nn_cart_empty').val() == 1) ? String(jQuery('#nn_order_amount').val() * jQuery('#quantity').val()) : String(jQuery('#nn_order_amount').val()),  
        currency        : jQuery('#nn_currency').val(),  
        paymentMethod   : paymentName,
        enforce3d       : (jQuery('#nn_enforce').val() == 'on') ? true : false,
        environment     : (configurationData[paymentName].testmode == 'on') ? "SANDBOX" : "PRODUCTION",
        setPendingPayment : true
    };
    return transactionDetails;
}

// Merchant Information
function merchantInfo(paymentName) {
    var merchantDetails = {
        countryCode :(jQuery('#nn_merchant_country_code').val() != '') ? jQuery('#nn_merchant_country_code').val() : 'DE',
        partnerId   : jQuery('#nn_merchant_id').val()
    };
    return merchantDetails;
}

// Order Information 
function orderInfo(paymentName) {
    var orderDetails = {
        merchantName    : configurationData[paymentName].seller_name,    
        lineItems  : (jQuery('#nn_cart_empty').val() == 1) ? [{
                                                                label : (jQuery('#nn_product_name').val() + '(' + jQuery('#quantity').val() + ' X ' + jQuery('#nn_product_amount').val() + ')'),
                                                                type  : "LINE_ITEM",
                                                                amount: String(jQuery('#nn_product_amount').val() * jQuery('#quantity').val())
                                                              }] : jQuery.parseJSON(jQuery("#nn_final_article_details").val()),
        billing         : {
            requiredFields: ["postalAddress", "phone", "email"]
        },
        shipping: {
            requiredFields: ["postalAddress", "phone", "email"] ,  
            methodsUpdatedLater : true                                                                 
        }
    };
    return orderDetails;
}

// Button  Information
function buttonInfo(paymentName) {
    var buttonDeTails = {
        type:  configurationData[paymentName].button_type,
        locale:  (jQuery('#nn_page_language').val() == 'eng') ? 'en-US' : 'de-DE',
        boxSizing: 'fill' ,
        dimensions: {
            height      : configurationData[paymentName].button_height,
        }
    };
    return buttonDeTails;
}

// OnprocessCompletion Function
function onprocessCompletion(payLoad, bookingResult, paymentName) {
    if (payLoad.result.status == 'SUCCESS') {
        // Set the wallet response to the post call
        payLoad.paymentType = paymentName;
        var ioPayload = { name : "novalnetWalletResponse", params : [{novalnetWalletResponse : payLoad}]};
        jQuery.ajax({
            url        : jQuery('#nn_shop_url').val() + '/io',
            type       : 'post',
            dataType   : 'json',
            data       : 'io='+JSON.stringify(ioPayload),
            global     : false,
            async      : false,
            success    : function (result) {
                window.location.href = jQuery('#nn_shop_url').val() + '/novalnetwallet-checkout-' + jQuery('#nn_page_language').val();
            }
        });
    } else {
        // Upon failure, displaying the error text 
        if (payLoad.result.statusText) {
            alert(payLoad.result.statusText);
        }
    }
}

// OnshippingContactChange Function
function onShippingContactChange(shippingContact, updatedRequestData, paymentName) {
    shippingContact.paymentType = paymentName;
    var ioPayload = { name : "novalnetShippingAddressUpdate", params : [{novalnetWalletShippingAddress : shippingContact}]};
    jQuery.ajax({
        url         : jQuery('#nn_shop_url').val() + '/io', 
        type        : 'post',
        dataType    : 'json',
        data        : 'io='+JSON.stringify(ioPayload),
        global      : false,
        async       : false,
        success     : function (result) {
            var decodedValue = JSON.parse(result);
            let updatedInfo = {};
            if ( decodedValue.shipping_address.length == 0 ) {
                updatedInfo.methodsNotFound = "There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.";
            } else if ( decodedValue.shipping_address.length ) {
                updatedInfo.amount            = String(decodedValue.amount);
                updatedInfo.lineItems         = decodedValue.article_details;
                updatedInfo.methods           = decodedValue.shipping_address;
                updatedInfo.defaultIdentifier = decodedValue.shipping_address[0].identifier;
            }
            updatedRequestData( updatedInfo );
        }
    });
}

// OnshippingMethodChange Function
function onShippingMethodChange(choosenShippingMethod, updatedRequestData, paymentName) {
    choosenShippingMethod.paymentType = paymentName;
    var ioPayload = { name : "novalnetShippingMethodUpdate", params : [{novalnetWalletShippingMethod : choosenShippingMethod}]};
    jQuery.ajax({
        url         : jQuery('#nn_shop_url').val() + '/io', 
        type        : 'post',
        dataType    : 'json',
        data        : 'io='+JSON.stringify(ioPayload),
        global      : false,
        async       : false,
        success     : function (result) {
            var decodedValue = JSON.parse(result);
            var updatedInfo = {
                amount      : String(decodedValue.amount),
                lineItems   : decodedValue.article_details
            };
                updatedRequestData(updatedInfo);
        }
    });
}

function onPaymentButtonClicked(clickResult) {
    // Add the product to the cart
    if(jQuery('#nn_product_page').val() == 1) {
        var jQueryform  = jQuery('#buy_form');
        var data   = jQueryform.serializeObject();
        var basket = jQuery.evo.basket();
        basket.pushedToBasket = function(response) {};
        data.a = jQuery('#nn_product_id').val();
        if(!processedProduct.hasOwnProperty(data.a) ) {
            processedProduct[data.a] = 1;
            basket.addToBasket(jQueryform, data);
        } 
    }          
}

function displayWalletButton(paymentName) {
    // Loading the payment instances
    var NovalnetPaymentInstance = NovalnetPayment();
    var novalnetPaymentObj = NovalnetPaymentInstance.createPaymentObject();

    // Setting up the payment intent in your object 
    novalnetPaymentObj.setPaymentIntent(paymentIntent);

    // Checking for the payment method availability
    novalnetPaymentObj.isPaymentMethodAvailable(function(displayPayButton) {
            if(displayPayButton) {
                // Initiating the Payment Request for the Wallet Payment
                if(jQuery('#nn_display_button').val() == 0) {
                    if(paymentName == 'APPLEPAY') {
                        novalnetPaymentObj.addPaymentButton("#nn_product_display_applepay_button");
                        jQuery('apple-pay-button').css('width', '100%', 'important');
                    }
                    if(paymentName == 'GOOGLEPAY') {
                        novalnetPaymentObj.addPaymentButton("#nn_product_display_googlepay_button");
                        $('.gpay-card-info-container').css('min-width', 0, 'important');
                    }
                } else {
                    if(paymentName == 'APPLEPAY'){
                        novalnetPaymentObj.addPaymentButton("#nn_cart_display_applepay_button");
                        jQuery('apple-pay-button').css('width', '100%', 'important');
                    }
                    if(paymentName == 'GOOGLEPAY') {
                        novalnetPaymentObj.addPaymentButton("#nn_cart_display_googlepay_button");
                    }
                }
            }
    });
}
