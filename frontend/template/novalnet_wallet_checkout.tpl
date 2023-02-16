{strip}
    <div class="container">
        <div class="row">
            <div class="col-12 col-xs-12">
                <h3>{lang key="billingAdress" section="checkout"}</h3>
                    <div id="billing-address" class="mb-5">
                        <p>
                            {include file='checkout/inc_billing_address.tpl' Kunde=$smarty.session.Kunde}
                        </p>
                    </div>
            </div>
        </div>
        {if $templateVariables.templateCheckoutStep === 'summaryPage'}
            {include file=$templateVariables.templateSummaryPage}
        {else}
            {* Should not happen - unknown step - display generic error message and do nothing else. *}
            <div class="alert alert-danger">{$oPlugin->getLocalization()->getTranslation('error_generic')}</div>
        {/if}
    </div>
{/strip}
