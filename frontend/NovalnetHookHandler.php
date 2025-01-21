<?php
/**
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
 * Script: NovalnetHookHandler.php
 *
*/

namespace Plugin\jtl_novalnet\frontend;

use JTL\Shop;
use JTL\Checkout\Bestellung;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;

/**
 * Class NovalnetHookHandler
 * @package Plugin\jtl_novalnet\frontend
 */
class NovalnetHookHandler
{
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;

    /**
     * NovalnetHookHandler constructor.
     */
    public function __construct()
    {
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();
    }
    
    /**
     * Display the Novalnet transaction comments on order status page when payment before order completion option is set to 'Ja'
     *
     * @param  array $args
     * @return none
     */
    public function orderStatusPage(array $args): void
    {
        if (strpos($_SESSION['Zahlungsart']->cModulId, 'novalnet') !== false && !empty($_SESSION['novalnet']['comments'] )) {
            $args['oBestellung']->cKommentar = $_SESSION['novalnet']['comments'] ;
        }
    }

    /**
     * Display the Novalnet transaction comments aligned in My Account page of the user
     *
     * @return none
     */
    public function accountPage(): void
    {
        if (!empty(Shop::Smarty()->tpl_vars['Bestellung'])) {
            Shop::Smarty()->tpl_vars['Bestellung']->value->cKommentar = nl2br(Shop::Smarty()->tpl_vars['Bestellung']->value->cKommentar);
            $lang = (Shop::Smarty()->tpl_vars['Bestellung']->value->kSprache == 1) ? 'ger' : 'eng';

            $orderNo = Shop::Smarty()->tpl_vars['Bestellung']->value->cBestellNr;
            $orderId = Shop::Smarty()->tpl_vars['Bestellung']->value->kBestellung;
            
            $getAdditionalInfo 		= Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid = :cNnorderid', [':cNnorderid' => $orderNo], 1);
            $getTransactionComments = json_decode($getAdditionalInfo->cAdditionalInfo, true);

            $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($orderNo, $lang, $orderId);
            Shop::Smarty()->assign('instalmentDetails', $instalmentInfo)
						  ->assign('nnComments', $getTransactionComments['cKommentar']);
        }
    }

    /**
     * Used for the frontend template customization for the Credit Card Logo and Barzahlen slip
     *
     * @param  array $args
     * @return none
     */
    public function contentUpdate(array $args): void
    {
        if(Shop::getPageType() == \PAGE_BESTELLABSCHLUSS) {
            $this->displayNnCashpaymentSlip();
        }
    }

    /**
     * Display the Barzahlen slip on success page
     *
     * @return none
     */
    public function displayNnCashpaymentSlip(): void
    {
        if (!empty($_SESSION['kBestellung'])) {

            $order = new Bestellung($_SESSION['kBestellung']);
            $paymentModule = Shop::Container()->getDB()->queryPrepared('SELECT cModulId FROM tzahlungsart WHERE kZahlungsart = :kZahlungsart', [':kZahlungsart' => $order->kZahlungsart], 1);
            
            // Verifies if the cashpayment token is set and loads the slip from Barzahlen accordingly.
            if ($paymentModule && strpos($paymentModule->cModulId, 'novalnet') !== false && !empty($_SESSION['novalnet_cashpayment_token'])) {

                pq('body')->append('<script src="'. $_SESSION['novalnet_cashpayment_checkout_js'] . '"
                                            class="bz-checkout"
                                            data-token="'. $_SESSION['novalnet_cashpayment_token'] . '"
                                            data-auto-display="true">
                                    </script>
                                    <style type="text/css">
                                        #bz-checkout-modal { position: fixed !important; }
                                        #barzahlen_button {width: max-content; margin-top: -30px !important; margin-bottom: 5% !important; margin-left: 20px !important; }
                                    </style>');

                pq('#order-confirmation')->append('<button id="barzahlen_button" class="bz-checkout-btn" onclick="javascript:bzCheckout.display();">' . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_slipurl') . '</button>');

                unset($_SESSION['novalnet_cashpayment_token']);
                unset($_SESSION['novalnet_cashpayment_checkout_js']);
            }
        }
    }

    /**
     * Change the WAWI pickup status as 'JA' before payment completion
     *
     * @param  array $args
     * @return none
     */
    public function changeWawiPickupStatus(array $args): void
    {
        if(!empty($args['oBestellung']->kBestellung) && strpos($_SESSION['Zahlungsart']->cModulId, 'novalnet') !== false) {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $args['oBestellung']->kBestellung, ['cAbgeholt' => 'Y']);
        }

    }
    
    /**
     * Change the payment name based on the customer chosen payment in the payment form
     *
     * @return none
     */
    public function updatePaymentName(): void
    {
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']) && strpos($_SESSION['Zahlungsart']->cModulId, 'novalnet') !== false) {
            $_SESSION['Zahlungsart']->cName = $_SESSION['Zahlungsart']->angezeigterName['ger'] = $_SESSION['Zahlungsart']->angezeigterName['eng'] = !empty($_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['name']) ? $_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['name'] . ' (Novalnet)' : 'Novalnet';
        }
    }
    
    /**
	 * Load the seamless payment form on the checkout
	 *
	 * @return none
	 */
	public function displayNnPaymentForm(): void
	{
		global $step;       
		$nnPaymentFormScript = '';
		
		foreach (Shop::Smarty()->tpl_vars['Zahlungsarten']->value as $payment) {
			 if (strpos($payment->cModulId, 'novalnet')) {
				if (in_array($step, ['Zahlung', 'Versand'])) {
					// Build the pay by link parameters
					$paymentFormRequestData = $this->novalnetPaymentGateway->generatePaymentParams($order = null, true);
					$paymentFormRequestData['hosted_page'] =    ['type' => 'PAYMENTFORM'];
					// Wallet order details
					$walletOrderDetails = htmlentities($this->novalnetPaymentGateway->getBasketDetails());
					if (!empty($_SESSION['novalnet']['order_amount'])) {
						$paymentFormRequestData['transaction']['amount'] = ( $_SESSION['novalnet']['order_amount'] > 0 ) ? $_SESSION['novalnet']['order_amount'] : 0;
					}
					// Send the payment form request to server
					$paymentFormResponseData = $this->novalnetPaymentGateway->performServerCall($paymentFormRequestData, 'seamless_payment');
					if(!empty($paymentFormResponseData)) {
						
						if ($paymentFormResponseData['result']['status'] == 'FAILURE') {
							//  Stay on the checkout page
							\header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
							exit;
						} else {
							// Novalnet payment plugin path
							$pluginPath = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getPaths()->getBaseURL();
							
									$nnPaymentFormScript .= <<<HTML
									<input type="hidden" name="nn_payment_id" id="nn_payment_id" value="{$payment->cModulId}">
									<style type="text/css">
										#$payment->cModulId .custom-control.custom-radio {display:none;}
										#$payment->cModulId {margin-left: -1.75rem;}
										.row.checkout-payment-options.form-group div {width: 100%;}
									</style>
									<fieldset>
										<iframe style="width:100%;border:0;scrolling:no;" id="v13PaymentForm" src="{$paymentFormResponseData['result']['redirect_url']}" allow = "payment"></iframe>
										<script type='text/javascript' src='https://cdn.novalnet.de/js/pv13/checkout.js'></script>
										<input type="hidden" name="nn_wallet_data" id="nn_wallet_data" value="{$walletOrderDetails}">
										<script type='text/javascript' src='{$pluginPath}frontend/js/novalnet_payment.min.js'  integrity='sha384-vcRAT07+mreVNJywn+MG94+0wsyN0/LZi9cV6kQc7wtSIcnUYtZ3kDdCgKliiX3J'></script>
										<div class="card-header alert-danger text-center mb-3 d-none" id="novalnet_payment_form_error_alert"></div>
									 </fieldset>
HTML;
						}
					} else {
						$nnPaymentFormScript .= <<<HTML
						<style type="text/css">
							   #$payment->cModulId .custom-control.custom-radio {display:none;}
						</style>	
HTML;
					}
				}
			 }
		}
		Shop::Smarty()->assign('novalnetPaymentForm', $nnPaymentFormScript)
		->assign('cModulId', $payment->cModulId);
	}
}
