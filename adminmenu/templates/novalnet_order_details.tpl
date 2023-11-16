{**
 * This file renders the transaction details
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
*}

<div id="nn_header">
    <center>
        {$languageTexts.jtl_novalnet_invoice_payments_order_number_reference} {$orderNo}
    </center>
    <a class="btn btn-primary nn_back_tab" href="{$menuId}"><span class="fal fa-long-arrow-left"></span></a>
</div>

<div class="body_div" id="overlay_window_block_body">
    <div class="nn_accordion">
        <div class="nn_accordion_section">
            <div id="nn_transaction_details" class="nn_accordion_section_content" style="display:block;">
                {if !empty($orderComment->cKommentar)}
                    <div>{$orderComment->cKommentar|nl2br}</div><br>
                {/if}
                {if !empty($instalmentDetails) && $instalmentDetails.status == 'CONFIRMED'}
                <div class="nn_instalment">
                    <h6 style="padding-bottom:1rem;">{$instalmentDetails.lang.jtl_novalnet_instalment_information}</h6>           
                    <table class="table table-striped nn_instalment_table">
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
                        {foreach from=$instalmentDetails['insDetails'] key="index" item=$instalment}
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
                </div>
            </div>
        </div>
    </div>
</div>
