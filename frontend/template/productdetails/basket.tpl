{block name='productdetails-basket-add-to-cart'}
    {col cols=12 sm=6}
        {button aria=["label"=>"{lang key='addToCart'}"] block=true name="inWarenkorb" type="submit" value="{lang key='addToCart'}" variant="primary" disabled=$Artikel->bHasKonfig && !$isConfigCorrect|default:false class="js-cfg-validate"}
            <span class="btn-basket-check">
                <span>
                {if isset($kEditKonfig)}
                    {lang key='applyChanges'}
                {else}
                    {lang key='addToCart'}
                {/if}
                </span> <i class="fas fa-shopping-cart"></i>
            </span>
        <svg x="0px" y="0px" width="32px" height="32px" viewBox="0 0 32 32">
            <path stroke-dasharray="19.79 19.79" stroke-dashoffset="19.79" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linecap="square" stroke-miterlimit="10" d="M9,17l3.9,3.9c0.1,0.1,0.2,0.1,0.3,0L23,11"/>
        </svg>
        {/button}
    {/col}
    {col cols=12 sm=6}<div></div>{/col}
    {col cols=12 sm=6}<div id='nn_product_display_applepay_button' style='float:left;margin-top:12px;width:100%;'></div>{/col}
    {col cols=12 sm=6}<div></div>{/col}
    {col cols=12 sm=6}<div id='nn_product_display_googlepay_button' style='float:left;margin-top:12px;width:100%'></div>{/col}
{/block}
                            

