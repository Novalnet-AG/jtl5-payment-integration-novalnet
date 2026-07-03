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
 * Novalnet Credit Card logos script
*/






function initNovalnetScript() {

    if ($('#nn_cc_payment_id').length > 0) {
        var payment_id     = $('#nn_cc_payment_id').val();
        var payment_logo   = ($('#nn_logos').val()).split('&');
        var nn_img_class   = $('#payment'+payment_id).next().children('img').attr('class');
  




        
        // Clear old icons (avoid duplicates)
        $('#payment'+payment_id).next().find("img").remove();

        for (var i=0,len = payment_logo.length; i<len; i++) {
            var logo_src   = payment_logo[i].split('=');
            var img_tag    = '<img src="'+decodeURIComponent(logo_src[1])+
                             '" class="'+nn_img_class+'" alt="'+$('#nn_logo_alt').val()+
                             '" hspace="1">';

            $('#payment'+payment_id).next().children('span').before(img_tag);
        }
    }

    if (jQuery("input[name*='nn_display_testmode']").length > 0)  {
        var arr = jQuery("input[name*='nn_display_testmode']").map(function () {
        var testmodeId = jQuery(this).attr('id');
        if (testmodeId && jQuery('[id="' + testmodeId + '"]').val() != '') {
            if (testmodeId.indexOf('novalnetkreditdebitkarte') > -1) {
                    var testmodeId = testmodeId.replace("novalnetkreditdebitkarte", "novalnetkredit");
                    jQuery('div[id="' + testmodeId + '"]').children().children().next().children('span').before('<span id=nn_testmode_'+ testmodeId+' class=novalnet_test_mode>' + jQuery('#nn_testmode_text').val() + '</span>');
            }
            if (jQuery('[id="nn_testmode_' + testmodeId + '"]').length == 0 ) { // If not set already
                jQuery('div[id="' + testmodeId + '"]').find('.novalnet_test_mode').remove();
                jQuery('div[id="' + testmodeId + '"]').children().children().next().children('span').before('<span id=nn_testmode_'+ testmodeId+' class=novalnet_test_mode>' + jQuery('#nn_testmode_text').val() + '</span>');
            }
        }
        }).get();
    }
}

// Run on initial load
jQuery(document).ready(function () {
    initNovalnetScript();
});


$(document).ajaxComplete(function(event, xhr, settings) {
    if (settings.url.includes("Checkout")) {
        initNovalnetScript();
    }
});
