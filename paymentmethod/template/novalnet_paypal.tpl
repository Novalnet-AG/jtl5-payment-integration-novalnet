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
 * Novalnet PayPal template
*}

<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>    
    <div class="nn_paypal">
        <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_paypal" /> 
        <div class="card-header alert-success text-center mb-3 d-none" id="novalnet_removal_error_alert"></div>
        {if (!empty($paypalDetails))}
            {foreach from=$paypalDetails key='key' item='value'}
                {assign var='maskingPaypalDetails' value=$value->cTokenInfo|json_decode:true}
                {if ($value->cStatuswert == CONFIRMED) }
                <div class="row" id="remove_{$maskingPaypalDetails.token}">
                    <div class="col-xs-12 nn_masked_details">
                        <input type="radio" name="nn_radio_option" value="{$maskingPaypalDetails.token}">
                        <span>
                            {$languageTexts.jtl_novalnet_paypal_account_label} {$maskingPaypalDetails.paypal_account}
                        </span>
                        <button type="button" class="btn droppos btn-link btn-sm" title="Remove" onclick="removeSavedDetails('{$value->nNntid}')" value="{$maskingPaypalDetails.token}">
                            <span class="fas fa-trash-alt"></span>
                        </button>
                    </div>
                </div>
                {/if}
            {/foreach}
            {if ($value->cStatuswert == CONFIRMED) }
                    <input type="hidden" name="nn_customer_selected_token" id="nn_customer_selected_token">                        
                    <input type="radio"  name="nn_radio_option" id="nn_toggle_form"> {$languageTexts.jtl_novalnet_add_new_account_details} {/if}
        {/if}
            
        {if $oneClickShoppingEnabled}
            <div class="row" id="nn_load_new_form">            
                <div class="col col-12 nn_save_payment">
                    <input type="checkbox" name="nn_save_payment_data" id="nn_save_payment_data" checked>                    
                    <span>{$languageTexts.jtl_novalnet_paypal_account_data}</span>
                </div>                       
            </div>
        {/if}
                
        <input type="hidden" id="remove_saved_payment_detail" value="{$languageTexts.jtl_novalnet_remove_account_detail}" />
        <input type="hidden" id="alert_text_payment_detail_removal" value="{$languageTexts.jtl_novalnet_account_detail_removed}" />        
        <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.js"></script> 
        <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>      
        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
    </div>
</fieldset>
