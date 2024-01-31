{**
 * This file renders the Novalnet orders in the order tab under the novalnet plugin
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
*}

<input type="hidden" name="nn_post_url" id="nn_post_url" value="{$postUrl}">
{if $orders|@count > 0 && $orders}
{include file='tpl_inc/pagination.tpl' pagination=$pagination cParam_arr=['kPlugin'=>$pluginId] cAnchor=$hash}

    <form method="post" name="bestellungen" action="{$postUrl}">
    {$jtl_token}
        <div class="table-responsive">           
            <table class="table table-striped nn_order_table">
            <thead>
                <tr>
                    <th>{$languageTexts.jtl_novalnet_order_number}</th>
                    <th>{$languageTexts.jtl_novalnet_customer_text}</th>
                    <th>{$languageTexts.jtl_novalnet_payment_name_text}</th>
                    <th>{$languageTexts.jtl_novalnet_wawi_pickup}</th>
                    <th>{$languageTexts.jtl_novalnet_order_status}</th>
                    <th>{$languageTexts.jtl_novalnet_total_amount_text}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach $orders as $order}
                <tr>
                    <td class="text-left">
                        <div>{$order->cBestellNr}</div>
                        <small class="text-muted"><i class="far fa-calendar-alt" aria-hidden="true"></i> {$order->dErstellt}</small>
                    </td>
                    <td>
                        <div>
                        {if isset($order->oKunde->cVorname) || isset($order->oKunde->cNachname) || isset($order->oKunde->cFirma)}
                            {$order->oKunde->cVorname} {$order->oKunde->cNachname}
                            {if isset($order->oKunde->cFirma) && $order->oKunde->cFirma|strlen > 0}
                                ({$order->oKunde->cFirma})
                            {/if}
                        {else}
                        {__('noAccount')}
                        {/if}
                        </div>
                        <small class="text-muted"><i class="fa fa-user" aria-hidden="true"></i> {$order->oKunde->cMail}</small>
                    </td>   
                    <td class="text-left">{$order->cZahlungsartName}</td>
                    <td class="text-center">
                        {if $order->cAbgeholt === 'Y'}
                            <i class="fal fa-check text-success"></i>
                        {else}
                            <i class="fal fa-times text-danger"></i>
                        {/if}
                    </td>
                    <td class="text-left">
                        
                        {if $paymentStatus[$order->cStatus]|in_array:$paymentStatus}
                            {$paymentStatus[$order->cStatus]}
                        {else}
                            {$order->cStatus}
                        {/if}
                    </td>   
                    <td class="text-center">{$order->WarensummeLocalized[0]}</td>
                    <td onclick="senddata('{$order->cBestellNr}')"><a href="#overlay_window_block_body"><i class="fa fa-eye"></i></a></td>
                </tr>
                {/foreach}
            </tbody>
            </table>
        </div>
    </form>
{else}
    <div class="alert alert-info"><i class="fa fa-info-circle"></i> {$languageTexts.jtl_novalnet_orders_not_available}</div>
{/if}
<div id="nn_transaction_info"></div>

