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
    <div class="nn_ach">
        <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_direct_debit_ach" />
          {if (!empty($AccountDetails))}         
            {foreach from=$AccountDetails key='key' item='value'}
                {assign var='maskingAccountDetails' value=$value->cTokenInfo|json_decode:true}
                <div class="row" id="remove_{$maskingAccountDetails.token}">
                    <div class="col-xs-12 nn_masked_details">
                        <input type="radio" name="nn_radio_option" value="{$maskingAccountDetails.token}">
                        <span>
                           
                            <img src="{$pluginPath}paymentmethod/images/novalnet_ach.png" alt="{$maskingAccountDetails.account_number}" title="{$maskingAccountDetails.account_number}">
                            {$languageTexts.jtl_novalnet_ach_account_number} {$maskingAccountDetails.account_number|substr:-4} {$languageTexts.jtl_novalnet_sepa_routing_number} {$maskingAccountDetails.routing_number|substr:-4}
                        </span>                        
                        <button type="button" class="btn droppos btn-link btn-sm" title="Remove" onclick="removeSavedDetails('{$value->nNntid}')" value="{$maskingAccountDetails.token}">
                            <span class="fas fa-trash-alt"></span>
                        </button>
                    </div>
                </div>
            {/foreach}
            <div class="row nn_add_new_details">  
                <div class="col-xs-12">
                    <input type="hidden" name="nn_customer_selected_token" id="nn_customer_selected_token">
                    <input type="radio"  name="nn_radio_option" id="nn_toggle_form"> {$languageTexts.jtl_novalnet_add_new_account_details}
                </div>
            </div>
        {/if}


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
        {if $oneClickShoppingEnabled}
					<div class="col col-12 nn_save_payment">
						<input type="checkbox" name="nn_save_payment_data" id="nn_save_payment_data" checked>                    
						<span>{$languageTexts.jtl_novalnet_save_account_data}</span>
					</div>
				{/if}
         <input type="hidden" id="remove_saved_payment_detail" value="{$languageTexts.jtl_novalnet_remove_account_detail}" />
        <input type="hidden" id="alert_text_payment_detail_removal" value="{$languageTexts.jtl_novalnet_account_detail_removed}" />
        <input type="hidden" id="nn_account_holder_invalid" value="{$languageTexts.jtl_novalnet_account_holder_invalid}" />
        <input type="hidden" id="nn_account_no_invalid" value="{$languageTexts.jtl_novalnet_account_number_invalid}" />
        <input type="hidden" id="nn_routing_no_invalid" value="{$languageTexts.jtl_novalnet_routing_number_invalid}" />
        <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.min.js" integrity="sha384-i3EvYU3dDye94BT9WSHPWvY8yJajJFFfsp4N+D80ULZlVqIzelmRZ7qqwGSodbdc"></script>
        <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>
        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
    </div>
</fieldset>
