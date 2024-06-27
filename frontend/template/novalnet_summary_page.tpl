{strip}
    <div class="row">
        <div class="col-12 col-xs-12">
            <div class="alert alert-warning" style="display:none;"></div>
        </div>
        <div class="col-12 col-xs-12 col-sm-6 mb-1  bottom2">
            <h3>{lang key="shippingAdress" section="checkout"}</h3>
            <p>{include file='checkout/inc_delivery_address.tpl' Lieferadresse=$smarty.session.Lieferadresse}</p>
        </div>
        <div class="col-12 col-xs-12 col-sm-6 mb-1 bottom2">
            <h3>{lang key="paymentMethod" section="checkout"}</h3>
            <p>{$templateVariables.paymentMethodName}</p>
        </div>
  
    </div>
    
    <form class="form evo-validate" method="post" id="nn_wallet_checkout_submit">
        {$jtl_token}
        <div class="row">
            <div class="col-xs-12 col-12">
                {lang key='agb' assign='agb'}
                {if !empty($AGB->cAGBContentHtml)}
                    {modal id="agb-modal" title=$agb}{$AGB->cAGBContentHtml}{/modal}
                {elseif !empty($AGB->cAGBContentText)}
                    {modal id="agb-modal" title=$agb}{$AGB->cAGBContentText}{/modal}
                {/if}
                {if $Einstellungen.kaufabwicklung.bestellvorgang_wrb_anzeigen == 1}
                    {lang key='wrb' section='checkout' assign='wrb'}
                    {lang key='wrbform' assign='wrbform'}
                    {if !empty($AGB->cWRBContentHtml)}
                        {modal id="wrb-modal" title=$wrb}{$AGB->cWRBContentHtml}{/modal}
                    {elseif !empty($AGB->cWRBContentText)}
                        {modal id="wrb-modal" title=$wrb}{$AGB->cWRBContentText}{/modal}
                    {/if}
                    {if !empty($AGB->cWRBFormContentHtml)}
                        {modal id="wrb-form-modal" title=$wrbform}{$AGB->cWRBFormContentHtml}{/modal}
                    {elseif !empty($AGB->cWRBFormContentText)}
                        {modal id="wrb-form-modal" title=$wrbform}{$AGB->cWRBFormContentText}{/modal}
                    {/if}
                {/if}

                <div class="checkout-confirmation-legal-notice">
                    <p>{$AGB->agbWrbNotice}</p>
                </div>

                {if !isset($smarty.session.cPlausi_arr)}
                    {assign var=plausiArr value=array()}
                {else}
                    {assign var=plausiArr value=$smarty.session.cPlausi_arr}
                {/if}

                {if !isset($cPost_arr)}
                    {assign var=cPost_arr value=$smarty.post}
                {/if}
                {hasCheckBoxForLocation bReturn="bCheckBox" nAnzeigeOrt=2 cPlausi_arr=$plausiArr cPost_arr=$cPost_arr}
                {if $bCheckBox }
                    <hr>
                    {getCheckBoxForLocation nAnzeigeOrt=2 cPlausi_arr=$plausiArr cPost_arr=$cPost_arr assign='checkboxes'}
                    {if !empty($checkboxes)}
                        {foreach $checkboxes as $cb}
                            {if $cb->nPflicht == 1}
                                <div class="row">
                                    <div class="col-12 col-xs-12">
                                        <div class="form-group">
                                            <div class="checkbox custom-control custom-checkbox">
                                                <input class="custom-control-input" type="checkbox" name="{$cb->cID}" value="Y" id="{if isset($cIDPrefix)}{$cIDPrefix}_{/if}{$cb->cID}"{if $cb->isActive} checked{/if}>
                                                <label class="control-label custom-control-label" for="{if isset($cIDPrefix)}{$cIDPrefix}_{/if}{$cb->cID}">
                                                    {$cb->cName}
                                                    {if !empty($cb->cLinkURL)}
                                                        <span class="moreinfo"> (<a href="{$cb->cLinkURL}" class="popup checkbox-popup">{lang key='read' section='account data'}</a>)</span>
                                                    {/if}
                                                </label>
                                            </div>
                                            {if !empty($cb->cBeschreibung)}
                                                <p class="description text-muted small">
                                                    {$cb->cBeschreibung}
                                                </p>
                                            {/if}
                                        </div>
                                    </div>
                                </div>
                            {/if}
                        {/foreach}
                    {/if}
                    <hr>
                {/if}
            </div>
        </div>
        <div class="row">
            {block name='checkout-confirmation-comment'}
                <div class="col-12 col-xs-12 mb-5 bottom10">
                    {if (!isset($smarty.session.kommentar) || empty($smarty.session.kommentar)) }
                        <p>
                            <a href="#" class="btn btn-primary" title="{lang key='orderComments' section='shipping payment'}" onclick="$(this).hide();$('#panel-edit-comment').show();return false;">
                                <i class="fa fas fa-pen fa-pencil"></i>&nbsp;{lang key='orderComments' section='shipping payment'}
                            </a>
                        </p>
                    {/if}
                    <div class="card panel panel-default" id="panel-edit-comment"{if (!isset($smarty.session.kommentar) || empty($smarty.session.kommentar))} style="display:none;"{/if}>
                        <div class="card-header panel-heading">
                            <h3 class="panel-title mb-0">{block name='checkout-confirmation-comment-title'}{lang key='comment' section='product rating'}{/block}</h3>
                        </div>
                        <div class="panel-body card-body">
                            {block name='checkout-confirmation-comment-body'}
                                {lang assign='orderCommentsTitle' key='orderComments' section='shipping payment'}
                                <textarea class="form-control border-0" autocomplete="nope" title="{$orderCommentsTitle|escape:'html'}" name="kommentar" cols="50" rows="3" id="comment" placeholder="{lang key='yourOrderComment' section='login'}">{if isset($smarty.session.kommentar)}{$smarty.session.kommentar}{/if}</textarea>
                            {/block}
                        </div>
                    </div>
                </div>
            {/block}
        </div>
        <div class="row">
            <div class="col-12 col-xs-12 order-submit">
                <div class="basket-final">
                    {include file='checkout/inc_order_items.tpl' tplscope="confirmation"}
                </div>
            </div>
        </div>

        <div class="row checkout-button-row">
            <div class="col ml-auto-util order-1 order-md-2 col-md-6 col-lg-4 col-12">
                <button class="btn btn-primary btn-lg submit submit_once" type="submit" style="display:block;">{lang key="orderLiableToPay" section="checkout"}</button>
            </div>
            <div class="col order-2 order-md-1 col-md-6 col-lg-3 col-12">
				<button class="btn btn-primary btn-lg submit submit_once" id="go-to-cart" type="submit" style="display:block;">{lang key='modifyBasket' section='checkout'}</button>
            </div>
        </div>
	</form>
        {* JS parts *}
    <script type="text/javascript">
        $('#nn_wallet_checkout_submit').on('submit', function (e) {
                e.preventDefault();
                 window.location.href = '{$ShopURLSSL}/novalnetwallet-return-{$pageLang}';
            });
    </script>
{/strip}
