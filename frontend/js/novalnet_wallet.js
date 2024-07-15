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

// Setting up the Payment Intent for authentication and payment button processing
var walletPayments = JSON.parse(jQuery("#nn_wallet_payments").val());
for (let walletPayment in walletPayments) {
    var merchantInformation = merchantInfo(walletPayments[walletPayment]);
    var orderInformation = orderInfo(walletPayments[walletPayment]);
    var buttonInformation = buttonInfo(walletPayments[walletPayment]);
    var paymentIntent = {
        clientKey: configurationData[walletPayments[walletPayment]].client_key,
        paymentIntent: {
            merchant: merchantInformation,
            transaction: transactionInfo(walletPayments[walletPayment]),
            order: orderInformation,
            button: buttonInformation,
            callbacks: {
                "onProcessCompletion": function(payLoad, bookingResult) {
                    // Handle response here and setup the bookingresult
                    if (payLoad.result && payLoad.result.status) {
                       onprocessCompletion(payLoad, bookingResult, walletPayments[walletPayment]);
                    }
                },
                "onShippingContactChange": function(shippingContact, newShippingContactResult) {
                      onShippingContactChange(shippingContact, newShippingContactResult, walletPayments[walletPayment]);
                 },
                 "onShippingMethodChange": function(shippingMethod, newShippingMethodResult) {
                      
                    onShippingMethodChange(shippingMethod, newShippingMethodResult, walletPayments[walletPayment]);
                 },
                 "onPaymentButtonClicked": function(clickResult) {
                     onPaymentButtonClicked(clickResult);
                    clickResult( {status: "SUCCESS"} );
                }
            }
        }
    };
    try {
        if((walletPayments[walletPayment] == "GOOGLEPAY"  && jQuery('#nn_gpay_enable').val() == 'on') || (walletPayments[walletPayment] == "APPLEPAY" && jQuery('#nn_apple_enable').val() == 'on')) {
            displayWalletButton(walletPayments[walletPayment]);
        }
    } catch (e) {
        // Handling the errors from the payment intent setup
        console.log(e.message);
    }

}
