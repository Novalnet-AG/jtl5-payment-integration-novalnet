{card class="card-gray basket-summary"}
    {block name='basket-index-proceed-button'}
        {link id="cart-checkout-btn" href="{get_static_route id='bestellvorgang.php'}?wk=1" class="btn btn-primary"}
            {lang key='nextStepCheckout' section='checkout'}
        {/link}
        <center><div id='nn_cart_display_applepay_button' style='margin-top:20px;width:100%;'></div></center>
        <div id='nn_cart_display_googlepay_button' style='margin-top:20px;width:100%;'></div>
        {/block}
{/card}
        
  
 
