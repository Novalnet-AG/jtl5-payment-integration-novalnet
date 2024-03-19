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
 * Novalnet Credit Card template
*}

<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>    
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_cc_error_alert"></div>
    <div class="card-header alert-success text-center mb-3 d-none" id="novalnet_removal_error_alert"></div>
    <div class="nn_cc">
        <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_cc" />
        {assign var="languageTexts" value=$creditcardFields|json_decode:true}
        {if (!empty($cardDetails))}         
            {foreach from=$cardDetails key='key' item='value'}
                {assign var='maskingCardDetails' value=$value->cTokenInfo|json_decode:true}
                <div class="row" id="remove_{$maskingCardDetails.token}">
                    <div class="col-xs-12 nn_masked_details">
                        <input type="radio" name="nn_radio_option" value="{$maskingCardDetails.token}">
                        <span>
                            {assign var='cardType' value=strtolower($maskingCardDetails.card_brand)}
                            <img src="{$pluginPath}paymentmethod/images/novalnet_{$cardType}.png" alt="{$maskingCardDetails.card_brand}" title="{$maskingCardDetails.card_brand}">
                            {$languageTexts.card_number_ending_details_label} {$maskingCardDetails.card_number|substr:-4} ({$languageTexts.card_expires_text} {$maskingCardDetails.card_expiry_month|string_format:"%02d"} / {$maskingCardDetails.card_expiry_year|substr:-2} )
                        </span>                        
                        <button type="button" class="btn droppos btn-link btn-sm" title="Remove" onclick="removeSavedDetails('{$value->nNntid}')" value="{$maskingCardDetails.token}">
                            <span class="fas fa-trash-alt"></span>
                        </button>
                    </div>
                </div>
            {/foreach}
            <div class="row nn_add_new_details">  
                <div class="col-xs-12">
                    <input type="hidden" name="nn_customer_selected_token" id="nn_customer_selected_token">
                    <input type="radio"  name="nn_radio_option" id="nn_toggle_form"> {$languageTexts.add_new_card_details}
                </div>
            </div>
        {/if}
            
        <div class="row" id="nn_load_new_form">                         
            {if $oneClickShoppingEnabled}
                <div class="col col-12 nn_save_payment">
                    <input type="checkbox" name="nn_save_payment_data" id="nn_save_payment_data" checked>                    
                    <span>{$languageTexts.save_card_data}</span>
                </div>
            {/if}
            <input type="hidden" id="nn_cc_panhash" name="nn_cc_panhash">
            <input type="hidden" id="nn_cc_uniqueid" name="nn_cc_uniqueid">
            <input type="hidden" id="nn_cc_3d_redirect" name="nn_cc_3d_redirect">
            <input id="nn_cc_formfields" type="hidden" value="{$creditcardFields|escape}" />
            <input id="nn_cc_formdetails" type="hidden" value="{$ccFormDetails|escape}" /> 
            <div class="col-xs-12 col-md-12">
                <iframe id="novalnet_cc_iframe" name="novalnet_cc_iframe" class="novalnet_cc_iframe" width="50%" frameborder="0" scrolling="no"></iframe>
            </div>
        </div><br><br>
                
        <input type="hidden" id="remove_saved_payment_detail" value="{$languageTexts.remove_card_detail}" />
        <input type="hidden" id="alert_text_payment_detail_removal" value="{$languageTexts.card_detail_removed}" />
        <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.js"  integrity="sha384-UBlDAk5yW8Is6d5EOLFcLf6NGBGUqxCzJKySqX0ZF7rOjEl0OT7PnrehN8Zr0QVe" ></script>
        <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>
        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
    </div>
</fieldset>
       


