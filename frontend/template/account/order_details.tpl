{**
 * Extended the shop template file for adding the instalment information
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
*}

{block name='account-order-details-order-comment'}
    {if !empty($Bestellung->cKommentar|trim)}
        <div class='h3'>{lang key='yourOrderComment' section='login'}</div>
        <p>{$Bestellung->cKommentar}</p>
    {/if}
    
    {if !empty($instalmentDetails) && $instalmentDetails.status == 'CONFIRMED'}
    <h6>{$instalmentDetails.lang.jtl_novalnet_instalment_information}</h6>           
    <table class="table table-striped table-bordered nn_instalment_table">
        <thead>
          <tr>
            <th>{$instalmentDetails.lang.jtl_novalnet_serial_no}</th>
            <th>{$instalmentDetails.lang.jtl_novalnet_instalment_future_date}</th>
            <th>{$instalmentDetails.lang.jtl_novalnet_instalment_transaction_id}</th>
            <th>{$instalmentDetails.lang.jtl_novalnet_order_status}</th>
            <th>{$instalmentDetails.lang.jtl_novalnet_instalment_amount}</th>
          </tr>
        </thead>
        <tbody>
        {foreach from=$instalmentDetails['insDetails'] key='index' item=$instalment}
          <tr>
            <td>{$index}</td>
            <td>{$instalment.future_instalment_date}</td>
            <td>{$instalment.tid}</td>
            <td>{$instalment.payment_status}</td>
            <td>{$instalment.cycle_amount}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    {/if}
{/block}
