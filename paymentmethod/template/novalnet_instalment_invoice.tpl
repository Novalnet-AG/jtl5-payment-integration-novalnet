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
 * Novalnet Instalment Invoice template
*}

<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_dob_error_alert"></div>
    <div class="nn_instalment_invoice">
            <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_instalment_invoice" />
            <input type="hidden" id="nn_order_amount" name="nn_order_amount" value="{$orderAmount/100}">
            <input type="hidden" id="nn_order_currency" name="nn_order_currency" value="{$currency}">
            <input type="hidden" id="nn_company" name="nn_company" value="{$company}">
            <input type="hidden" id="novalnet_dob_empty" name="novalnet_dob_empty" value="{$languageTexts.jtl_novalnet_birthdate_error}">
            <input type="hidden" id="novalnet_dob_invalid" name="novalnet_dob_invalid" value="{$languageTexts.jtl_novalnet_age_limit_error}">
            <div class="form-group " role="group" id="nn_show_dob">
                {if (!empty($birthDay))}
					<input type="date" class="form-control" id="nn_dob" name="nn_dob" value="{$birthDay}">
                {else}
					<input type="date" class="form-control" id="nn_dob" name="nn_dob">
                {/if}
                <label for="nn_dob" class="col-form-label pt-0">{$languageTexts.jtl_novalnet_dob}</label>
            </div>
            
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
                                        <option value="{$key}" data-amount="{$value} {$currency}" data-cycle-amount="{$cycleAmount/100}">{$key} x {$value} {$currency} {$languageTexts.jtl_novalnet_instalment_per_month_text}</option>
                                        
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
    </div>
        
    <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>    
    <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.js"  integrity="sha384-UBlDAk5yW8Is6d5EOLFcLf6NGBGUqxCzJKySqX0ZF7rOjEl0OT7PnrehN8Zr0QVe" ></script>
    <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">

</fieldset>


