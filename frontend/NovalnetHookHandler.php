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
        $refObject = Shop::Smarty();
        if ($refObject) {
            $refArray = (array)$refObject;
            if (isset($refArray["\0*\0smarty4"]) && $refArray["\0*\0smarty4"] !== null) {
                $smarty4 = $refArray["\0*\0smarty4"];
                if (isset($smarty4->tpl_vars)) {
                    $tplVars = $smarty4->tpl_vars;
                }
            }
        }
        $Zahlungsarten = (!empty(Shop::Smarty()->tpl_vars['Bestellung']->value ?? null))
        ? Shop::Smarty()->tpl_vars['Bestellung']->value : ($tplVars['Bestellung']->value ?? null);
        if (!empty($Zahlungsarten)) {
             $Zahlungsarten->cKommentar = nl2br($Zahlungsarten->cKommentar);
            $lang = ($Zahlungsarten->kSprache == 1) ? 'ger' : 'eng';
            $orderNo = $Zahlungsarten->cBestellNr;
            $orderId = $Zahlungsarten->kBestellung;
            $getAdditionalInfo 		= Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid = :cNnorderid', [':cNnorderid' => $orderNo], 1);
            if (!empty($getAdditionalInfo->cAdditionalInfo)) {
                $getTransactionComments = json_decode((string)$getAdditionalInfo->cAdditionalInfo, true);
            }
            $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($orderNo, $lang, $orderId);
            Shop::Smarty()->assign('instalmentDetails', $instalmentInfo)
						  ->assign('nnComments', $getTransactionComments['cKommentar']);
        }
    }

    /**
     * Used for frontend template customization to display transaction details
     *
     * @param  array $args
     * @return none
     */
    public function contentUpdate(array $args): void
    {
        if(Shop::getPageType() == \PAGE_BESTELLABSCHLUSS) {
            $this->displayTranscationdetails();
        }
    }

    /**
     * Display the Transcation details on success page
     *
     * @return none
     */
   
     public function displayTranscationdetails(): void
     {
        if (!empty($_SESSION['kBestellung'])) {

            $order = new Bestellung($_SESSION['kBestellung']);
            $transcationdetails = Shop::Container()->getDB()->queryPrepared(
                'SELECT cAdditionalInfo,cZahlungsmethode,nNntid 
                 FROM xplugin_novalnet_transaction_details 
                 WHERE cNnorderid = :cNnorderid',
                [':cNnorderid' => $order->cBestellNr],
                1
            );
        
            if (!empty($transcationdetails)) {
                $info = $transcationdetails->cAdditionalInfo;
                $decodedInfo = json_decode($info, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedInfo) && !empty($decodedInfo['cKommentar'])) {
                    $comment = $decodedInfo['cKommentar'];
                } else {
                    $comment = $info;
                }
                $allowedTags = '<img><br><br/><p><b><strong><i>';
                $comment = strip_tags($comment, $allowedTags);
                $comment = preg_replace(["/\r\n|\r|\n/", "/(\n\s*){2,}/"], ["\n", "\n"], trim($comment));
                $comment = preg_replace(
                    '/(<img[^>]*>)/i',
                    '<div style="text-align:center; margin-top:10px;">$1</div>',
                    $comment
                );
                $txnBox = '
                    <div class="card" style="margin-top:15px; padding:15px;">
                       <h5 class="card-title" style="margin-bottom:10px;">'
            . PHP_EOL .
            $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_transaction_details') .
        '</h5>
                        <div class="card-body" style="white-space:pre-wrap; line-height:1.5;">'
                            . $comment .
                        '</div>
                    </div>';
        
                pq('#order-confirmation')->append($txnBox);
        
                unset($_SESSION['novalnet_transcation_details']);
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
	 * @var Smarty $smarty4 
	 * @return none
	 */
	public function displayNnPaymentForm(): void
	{
		global $step;
		$nnPaymentFormScript = '';
        $refObject = Shop::Smarty();
        if ($refObject) {
            $refArray = (array)$refObject;
            if (isset($refArray["\0*\0smarty4"]) && $refArray["\0*\0smarty4"] !== null) {
                $smarty4 = $refArray["\0*\0smarty4"];
                if (isset($smarty4->tpl_vars)) {
                    $tplVars = $smarty4->tpl_vars;
                }
            }
        }
        $Zahlungsarten = (!empty(Shop::Smarty()->tpl_vars['Zahlungsarten']->value ?? null))
        ? Shop::Smarty()->tpl_vars['Zahlungsarten']->value : ($tplVars['Zahlungsarten']->value ?? null);
		foreach ( $Zahlungsarten as $payment) {
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
										<script type='text/javascript' src='{$pluginPath}frontend/js/novalnet_payment.min.js'  integrity='sha384-fKadKR75cpO5Z1HPsA598VGViQEXmMEbuKPgZ3Iho1Pl/1WOq8DkNy4MckeDHdQv'></script>
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
