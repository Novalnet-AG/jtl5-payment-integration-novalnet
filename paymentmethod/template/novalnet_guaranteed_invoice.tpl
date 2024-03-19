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
 * Novalnet Guaranteed Invoice template
*}

<fieldset>
    <legend>{$smarty.session['Zahlungsart']->angezeigterName[$smarty.session['cISOSprache']]}</legend>
    <div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_dob_error_alert"></div>
    <div class="nn_guaranteed_invoice">
            <input type="hidden" id="nn_payment" name="nn_payment" value="novalnet_guaranteed_invoice" />
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
    </div>
       
    <script type="text/javascript" src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js"></script>
    <script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_payment_form.js"  integrity="sha384-UBlDAk5yW8Is6d5EOLFcLf6NGBGUqxCzJKySqX0ZF7rOjEl0OT7PnrehN8Zr0QVe" ></script>
    <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">        
</fieldset>


