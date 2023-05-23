<input type="hidden" name="nn_merchant_id" id="nn_merchant_id" value="{$merchantId}">
<input type="hidden" name="nn_enforce" id="nn_enforce" value="{$enforce}">
<script>var walletPaymentData = jQuery.parseJSON('{$walletDetails}');</script>

<div id="wallet_container" name="wallet_container" class="btn submit_once" value="1" type="submit" >
    <input type="hidden" id="nn_wallet_token" name="nn_wallet_token"/>
    <input type="hidden" id="nn_wallet_amount" name="nn_wallet_amount" />
    <input type="hidden" id="nn_wallet_doredirect" name="nn_wallet_doredirect" />
</div>

<script src="https://cdn.novalnet.de/js/v3/payment.js"></script>
<script type="text/javascript" src="{$pluginPath}paymentmethod/js/novalnet_wallet_payment.js" defer></script>
