{**
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
 * Novalnet admin info template
*}

<input type="hidden" name="nn_post_url" id="nn_post_url" value="{$postUrl}">
<input type="hidden" name="nn_lang_notification" id="nn_lang_notification" value="{$languageTexts.jtl_novalnet_notification_text}">
<input type="hidden" name="nn_webhook_configure" id="nn_webhook_configure" value="{$languageTexts.jtl_novalnet_configure_webhook}">
<input type="hidden" name="nn_webhook_change" id="nn_webhook_change" value="{$languageTexts.jtl_novalnet_webhook_alert_text}">
<input type="hidden" name="nn_webhook_invalid" id="nn_webhook_invalid" value="{$languageTexts.jtl_novalnet_webhook_error_text}">
<input type="hidden" name="nn_webhook_success" id="nn_webhook_success" value="{$languageTexts.jtl_novalnet_webhook_notification_text}">
<input type="hidden" name="nn_webhook_url" id="nn_webhook_url" value="{$webhookUrl}">
<input type="hidden" name="nn_shop_lang" id="nn_shop_lang" value="{$shopLang}">
<input type="hidden" name="nn_invalid_due_date" id="nn_invalid_due_date" value="{$languageTexts.jtl_novalnet_due_date_error_text}">
<input type="hidden" name="nn_guarantee_min_amount_error_text" id="nn_guarantee_min_amount_error_text" value="{$languageTexts.jtl_novalnet_guarantee_min_amount_error_text}">
<input type="hidden" name="nn_instalment_min_amount_error_text" id="nn_instalment_min_amount_error_text" value="{$languageTexts.jtl_novalnet_instalment_min_amount_error_text}">
<input type="hidden" name="nn_instalment_payment_conditions" id="nn_instalment_payment_conditions" value="{$languageTexts.jtl_novalnet_instalment_condition_text}">
<input type="hidden" name="nn_guarantee_payment_conditions" id="nn_guarantee_payment_conditions" value="{$languageTexts.jtl_novalnet_guarantee_condition_text}">
<input type="hidden" name="nn_paypal_one_click_notification" id="nn_paypal_one_click_notification" value="{$languageTexts.jtl_novalnet_paypal_one_click_accept}">
<input type="hidden" name="nn_webhook_notification" id="nn_webhook_notification" value="{$languageTexts.jtl_novalnet_webhook_notification}">
<input type="hidden" name="nn_multiselect_text" id="nn_multiselect_text" value="{$languageTexts.jtl_novalnet_multiselect_option_text}">
<input type="hidden" name="nn_paypal_api_configure" id="nn_paypal_api_configure" value='{$languageTexts.jtl_novalnet_paypal_configuration_text}'>

<div class="row">
    <div class="col col-12">
        {$languageTexts.jtl_novalnet_info_page_text}
    </div>
</div>

<link rel="stylesheet" type="text/css" href="{$adminUrl}css/novalnet_admin.css">
<script type="text/javascript" src="{$adminUrl}js/novalnet_admin.js"></script> 
