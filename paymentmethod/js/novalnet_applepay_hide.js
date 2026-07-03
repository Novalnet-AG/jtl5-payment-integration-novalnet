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
 * Novalnet checkout page script hide the Apple pay
*/

jQuery('document').ready (function () {
   displayApplePay();
    jQuery(document).on( 'change', 'input[name="Versandart"]',function() {
        displayApplePay();
    });
});

function displayApplePay() {
    var paymentName = jQuery('#nn_applepay_id').val();
    // Hide the Apple Pay payment
    if(jQuery('#nn_applepay_id').val()) {
        var iosDevicce = iOS();
        var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        if(iosDevicce && isSafari) {
            jQuery("div#" + paymentName ).show();
        } else {
            jQuery("div#" + paymentName ).hide();
        }
    }
}

function iOS() {
    return [
      'iPad Simulator',
      'iPhone Simulator',
      'iPod Simulator',
      'iPad',
      'iPhone',
      'iPod'
    ].includes(navigator.platform)
    // iPad on iOS 13 detection
    || (navigator.userAgent.includes("Mac"))
}
