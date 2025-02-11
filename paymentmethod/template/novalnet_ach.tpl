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
 * Novalnet Direct Debit ACH template
*}
 
<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>  
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_ach_holder_error_alert"></div>  
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_ach_error_alert"></div>
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_routing_error_alert"></div>
    <div class="nn_sepa">
        <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_direct_debit_ach" />
        <div class="row" id="nn_load_new_form">
			 <div class="form-group col col-7 nn_iban_field" role="group">                
                <input type="text" class="form-control nn_holder" id="nn_ach_account_holder" name="nn_ach_account_holder" size="32" autocomplete="off" value= "{$firstName} {$lastName}">
                <label for="nn_ach_account_holder" class="col-form-label pt-0 nn_iban_label">{$languageTexts.jtl_novalnet_account_holder_name}</label>
            </div>
            <div class="form-group col col-7 nn_iban_field" role="group">                
                <input type="text" class="form-control nn_number" id="nn_ach_account_no" name="nn_ach_account_no" size="32" autocomplete="off">
                <label for="nn_ach_account_no" class="col-form-label pt-0 nn_iban_label">{$languageTexts.jtl_novalnet_ach_account_number}</label>
            </div>
            <div class="form-group col col-7 nn_iban_field" role="group">                
                <input type="text" class="form-control nn_number" id="nn_ach_routing_no" name="nn_ach_routing_no" size="32" autocomplete="off">
                <label for="nn_ach_routing_no" class="col-form-label pt-0 nn_iban_label">{$languageTexts.jtl_novalnet_sepa_routing_number}</label>
            </div>
        </div>
        <input type="hidden" id="nn_account_holder_invalid" value="{$languageTexts.jtl_novalnet_account_holder_invalid}" />
        <input type="hidden" id="nn_account_no_invalid" value="{$languageTexts.jtl_novalnet_account_number_invalid}" />
        <input type="hidden" id="nn_routing_no_invalid" value="{$languageTexts.jtl_novalnet_routing_number_invalid}" />
        <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.min.js" integrity="sha384-i3EvYU3dDye94BT9WSHPWvY8yJajJFFfsp4N+D80ULZlVqIzelmRZ7qqwGSodbdc"></script>
        <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>
        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
    </div>
</fieldset>
