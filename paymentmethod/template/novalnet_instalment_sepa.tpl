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
 * Novalnet Instalment Direct Debit SEPA template
*}
 
<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>    
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_dob_error_alert"></div>
    <div class="card-header alert-success text-center mb-3 d-none" id="novalnet_removal_error_alert"></div>
    <div class="nn_sepa">
        <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_instalment_sepa" />
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
                <input type="hidden" name="nn_customer_selected_token" id="nn_customer_selected_token">               <input type="radio"  name="nn_radio_option" id="nn_toggle_form"> {$languageTexts.jtl_novalnet_add_new_account_details}
                </div>
            </div>
        {/if}
            
        <div class="row" id="nn_load_new_form">
            {if $oneClickShoppingEnabled}
                <div class="col col-12 nn_save_payment">
                    <input type="checkbox" name="nn_save_payment_data" id="nn_save_payment_data" checked>            <span>{$languageTexts.jtl_novalnet_save_account_data}</span>
                </div>
            {/if}
            <div class="form-group col col-7 nn_iban_field" role="group">                
                <input type="text" class="form-control" id="nn_sepa_account_no" name="nn_sepa_account_no" size="32" autocomplete="off" onkeypress="return NovalnetUtility.formatIban(event);" onchange="return NovalnetUtility.formatIban(event);" style="text-transform:uppercase;">
                <label for="nn_sepa_account_no" class="col-form-label pt-0 nn_iban_label">{$languageTexts.jtl_novalnet_sepa_account_number}</label>
            </div>
        </div><br>
        
        <input type="hidden" id="nn_company" name="nn_company" value="{$company}">
        <input type="hidden" id="novalnet_dob_empty" name="novalnet_dob_empty" value="{$languageTexts.jtl_novalnet_birthdate_error}">
        <input type="hidden" id="novalnet_dob_invalid" name="novalnet_dob_invalid" value="{$languageTexts.jtl_novalnet_age_limit_error}">
        <input type="hidden" id="nn_order_amount" name="nn_order_amount" value="{$orderAmount/100}">
        <input type="hidden" id="nn_order_currency" name="nn_order_currency" value="{$currency}">
        
        <div class="row">
            <div class="col col-12">
                <div class="form-group " role="group" id="nn_show_dob">
                        {if (!empty($birthDay))}
							<input type="date" class="form-control" id="nn_dob" name="nn_dob" value="{$birthDay}">
						{else}
							<input type="date" class="form-control" id="nn_dob" name="nn_dob">
						{/if}
                        <label for="nn_dob" class="col-form-label pt-0">{$languageTexts.jtl_novalnet_dob}</label>
                </div>
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
        </div><br>
        
        {if !empty($instalmentCyclesAmount)}
            <div class="form-group " role="group">
                    <span>{$languageTexts.jtl_novalnet_instalment_plan} <strong>({$languageTexts.jtl_novalnet_net_amount} {$netAmount} )<span style="color:red;"> * </span></strong></span>
            </div>
            
            <div class="row nn_instalment_cycle_selection_block">
                <div class="col col-12">
                    <div class="form-group" role="group">
                        <select id="nn_instalment_cycle" name="nn_instalment_cycle" class="form-control">
                                {foreach from=$instalmentCyclesAmount key='key' item='value'}                                    
                                {assign var="cycleAmount" value=$value|replace:[",", "."]:""}
                                        <option value="{$key}" data-amount="{$value} {$currency}" data-cycle-amount="{$cycleAmount/100}">{$key} x {$value} {$currency}  {$languageTexts.jtl_novalnet_instalment_per_month_text}</option>                                 
                                {/foreach}
                        </select>
                        <label for="nn_instalment_cycle">{$languageTexts.jtl_novalnet_instalment_cycles}</label>
                    </div>                                   
                </div>
            </div>

            <div class="container">           
                <table class="table table-striped nn_instalment_table">
                    <thead>
                        <tr>
                            <th>{$languageTexts.jtl_novalnet_instalment_cycles}</th>
                            <th>{$languageTexts.jtl_novalnet_instalment_amount}</th>
                        </tr>
                    </thead>
                    <tbody id="nn_instalment_cycle_information">                                
                    </tbody>
                </table>
            </div>                    
        {/if}
        
        <input id="remove_saved_payment_detail" type="hidden" value="{$languageTexts.jtl_novalnet_remove_account_detail}" />
        <input id="alert_text_payment_detail_removal" type="hidden" value="{$languageTexts.jtl_novalnet_account_detail_removed}" />
        <input id="nn_account_invalid" type="hidden" value="{$languageTexts.jtl_novalnet_account_detail_invalid}" />
        <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.js"></script>
        <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>
        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
    </div>
</fieldset>



