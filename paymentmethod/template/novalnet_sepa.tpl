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
 * Novalnet Direct Debit SEPA template
*}
 
<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>    
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_sepa_error_alert"></div>
    <div class="card-header alert-success text-center mb-3 d-none" id="novalnet_removal_error_alert"></div>
    <div class="nn_sepa">
        <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_sepa" />
        {if (!empty($accountDetails))}
            {foreach from=$accountDetails key='key' item='value'}
                {assign var='maskingAccountDetails' value=$value->cTokenInfo|json_decode:true}
                <div class="row" id="remove_{$maskingAccountDetails.token}">
                    <div class="col-xs-12 nn_masked_details">
                        <input type="radio" name="nn_radio_option" value="{$maskingAccountDetails.token}">
                        <span>
                            <img src="{$pluginPath}paymentmethod/images/novalnet_sepa.png" alt="{$paymentName}" title="{$paymentName}"> {$maskingAccountDetails.iban}
                        </span>
                        <button type="button" class="btn droppos btn-link btn-sm" title="Remove" onclick="removeSavedDetails('{$value->nNntid}')" value="{$maskingAccountDetails.token}">
                            <span class="fas fa-trash-alt"></span>
                        </button>
                    </div>
                </div>
            {/foreach}
            <div class="row nn_add_new_details">
                <div class="col-xs-12">
                    <input type="hidden" name="nn_customer_selected_token" id="nn_customer_selected_token">          <input type="radio"  name="nn_radio_option" id="nn_toggle_form"> {$languageTexts.jtl_novalnet_add_new_account_details}
                </div>
            </div>
        {/if}
            
        <div class="row" id="nn_load_new_form">
            {if $oneClickShoppingEnabled}
                <div class="col col-12 nn_save_payment">
                    <input type="checkbox" name="nn_save_payment_data" id="nn_save_payment_data" checked>                    
                    <span>{$languageTexts.jtl_novalnet_save_account_data}</span>
                </div>
            {/if}
            <div class="form-group col col-7 nn_iban_field" role="group">                
                <input type="text" class="form-control" id="nn_sepa_account_no" name="nn_sepa_account_no" size="32" autocomplete="off" onkeypress="return NovalnetUtility.checkIban(event, 'bic_div');" onkeyup="return NovalnetUtility.formatIban(event, 'bic_div');" onchange="return NovalnetUtility.formatIban(event, 'bic_div');" style="text-transform:uppercase;">
                <label for="nn_sepa_account_no" class="col-form-label pt-0 nn_iban_label">{$languageTexts.jtl_novalnet_sepa_account_number}</label>
            </div>
            <div class="form-group col col-7 nn_bic_field" id="bic_div" role="group" style="display:none;">                
                <input type="text" class="form-control" name="nn_sepa_bic" id="nn_sepa_bic" size="32" autocomplete="off" onkeypress="return NovalnetUtility.formatBic(event);" onchange="return NovalnetUtility.formatBic(event);">
                <label for="nn_sepa_bic" class="col-form-label pt-0 nn_bic_label">{$languageTexts.jtl_novalnet_sepa_bic}</label>
            </div>
        </div>
        
        <div class="row">
            <div class="col col-12">
                <a href="#iban_details" id="nn_iban_mandate" data-toggle="collapse"><span class="text-primary">{$languageTexts.jtl_novalnet_sepa_mandate_text}</span></a>
                <div id="iban_details" class="collapse card-body">
                    <div>{$languageTexts.jtl_novalnet_sepa_mandate_instruction_one}</div>
                    <div>{$languageTexts.jtl_novalnet_sepa_mandate_instruction_two}</div>
                    <div>{$languageTexts.jtl_novalnet_sepa_mandate_instruction_three}</div>
                </div>
            </div>
        </div>
                
        <input type="hidden" id="remove_saved_payment_detail" value="{$languageTexts.jtl_novalnet_remove_account_detail}" />
        <input type="hidden" id="alert_text_payment_detail_removal" value="{$languageTexts.jtl_novalnet_account_detail_removed}" />
        <input type="hidden" id="nn_account_invalid" value="{$languageTexts.jtl_novalnet_account_detail_invalid}" />
        <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.js"  integrity="sha384-UBlDAk5yW8Is6d5EOLFcLf6NGBGUqxCzJKySqX0ZF7rOjEl0OT7PnrehN8Zr0QVe" ></script>
        <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>
        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
    </div>
</fieldset>
