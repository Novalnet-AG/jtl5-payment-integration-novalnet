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
 * Novalnet wallet payment script
*/  

jQuery(document).ready(function() {
    jQuery(".btn-primary").hide();      
    
    // Notify the end customer if the end customer use the Applepay not supported browser
    if(jQuery('#nn_applepay_not_support').val()) {
        var iosDevice = iOS();
        var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        if(!iosDevice && !isSafari) {
            jQuery('#novalnet_applepay_error_alert').removeClass('d-none');
        }
    }
});

var articleDetails = walletPaymentData.article_details;
    
// Setting up the Payment Intent for authentication and payment button processing
var merchantInformation = {
    countryCode: (walletPaymentData.country_code) ? walletPaymentData.country_code : 'DE',
    partnerId  : jQuery("#nn_merchant_id").val()
};

var transactionInformation = {
    amount       : String(walletPaymentData.amount),
    currency     : walletPaymentData.currency,    
    paymentMethod: walletPaymentData.payment_type,
    enforce3d    : Boolean(jQuery("#nn_enforce").val()),
    environment  : (walletPaymentData.test_mode == 'on') ? 'SANDBOX' : 'PRODUCTION'
};

var buttonInformation = {
    type      : walletPaymentData.button_type,
    locale    : (walletPaymentData.lang == 'eng') ? 'en-US': 'de-DE',
    boxSizing : "fill",
    dimensions: {
        height: walletPaymentData.button_height,
    }
};

var orderInformation = {
    merchantName : walletPaymentData.seller_name,    
    lineItems    : articleDetails,

};

var paymentIntent = {
    clientKey    : walletPaymentData.client_key,
    paymentIntent: {
        merchant   : merchantInformation,
        transaction: transactionInformation,
        order      : orderInformation,
        button     : buttonInformation,
        callbacks: {
            "onProcessCompletion": function(payLoad, bookingResult) {  
                // Handle response here and setup the bookingresult
                if (payLoad.result && payLoad.result.status) {

                    // Only on success, we proceed further with the booking   
                    if (payLoad.result.status == 'SUCCESS') {
                        // Sending the token and amount to Novalnet server for the booking
                        jQuery('#nn_wallet_token').val(payLoad.transaction.token);
                        jQuery('#nn_wallet_amount').val(payLoad.transaction.amount);
                        jQuery('#nn_wallet_doredirect').val(payLoad.transaction.doRedirect);
                        document.getElementById('form_payment_extra').submit();             

                    } else {

                        // Upon failure, displaying the error text 
                        if (payLoad.result.statusText) {
                            alert(payLoad.result.statusText);
                        }
                    }
                }
            },
                            
        }
    }
};

try {
        //Google Pay and Apple Pay button load
        if(walletPaymentData.payment_type == "GOOGLEPAY" || walletPaymentData.payment_type == "APPLEPAY") {
        // Loading the payment instances
        var novalnetPaymentInstance = NovalnetPayment();
        var novalnetPaymentObj = novalnetPaymentInstance.createPaymentObject();

        // Setting up the payment intent in your object 
        novalnetPaymentObj.setPaymentIntent(paymentIntent);

        // Checking for the payment method availability
        novalnetPaymentObj.isPaymentMethodAvailable(function(displayPaymentButton) {
                if (displayPaymentButton) {
                    // Initiating the Payment Request for the Wallet Payment
                    novalnetPaymentObj.addPaymentButton("#wallet_container");
                }
            });
        }
} catch(e) {
    // Handling the errors from the payment intent setup
    console.log(e.message);
}

function iOS() {
    return [ 'iPad Simulator', 'iPhone Simulator', 'iPod Simulator', 'iPad', 'iPhone', 'iPod'].includes(navigator.platform) || (navigator.userAgent.includes("Mac")) // iPad on iOS 13 detection
}
