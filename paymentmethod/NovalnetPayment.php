<?php 
/**
 * This file is used for processing the payment method
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: NovalnetPayment.php
 *
*/

namespace Plugin\jtl_novalnet\paymentmethod;

use JTL\Plugin\Payment\Method;
use JTL\Checkout\Bestellung;
use JTL\Shop;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;
use JTL\Alert\Alert;
use stdClass;

/**
 * Class NovalnetPayment
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetPayment extends Method
{     
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;
    
    /**
     * @var string
     */
    public $name;
    
    /**
     * @var string
     */
    public $caption;
    
    /**
     * NovalnetPayment constructor.
     * 
     * @param string $moduleID
     */
    public function __construct(string $moduleID)
    {
        // Preparing the NovalnetGateway object for calling the Novalnet's Gateway functions 
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();
        parent::__construct($moduleID);
    }
    
    /**
     * Sets the name and caption for the payment method - required for WAWI Synchronization
     * 
     * @param  int $nAgainCheckout
     * @return $this
     */
    public function init(int $nAgainCheckout = 0): self
    {
        parent::init($nAgainCheckout);

        $this->name    = 'Novalnet';
        $this->caption = 'Novalnet';

        return $this;
    }
    
    /**
     * Check the payment condition for displaying the payment on payment page
     * 
     * @param  array $args_arr
     * @return bool
     */
    public function isValidIntern(array $args_arr = []): bool
    {
        return ($this->novalnetPaymentGateway->canPaymentMethodProcessed());
    }
    
    /**
     * Called when additional template is used
     *
     * @param  array $post
     * @return bool
     */
    public function handleAdditional(array $post): bool
    {       
        if (!empty($post['nn_seamless_payment_form_response'])) {
			$decoded_response = base64_decode($post['nn_seamless_payment_form_response']);
            $_SESSION['novalnet']['seamless_payment_form_response'] = json_decode(mb_convert_encoding($decoded_response, 'UTF-8', mb_detect_encoding($decoded_response)), true);
        }
        return true;
    }
    
    /**
     * Initiates the Payment process
     * 
     * @param  Bestellung    $order
     * @return none
     */
    public function preparePaymentProcess(Bestellung $order): void
    {
        $paymentType = !empty($_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['type']) ? $_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['type'] : 'novalnet';
        // Set the Novalnet payment methods key as payment method type for further payment process handling
        $_SESSION['novalnet']['payment_key'] = strtolower($paymentType);
        if (!isset($_SESSION['novalnet']['seamless_payment_form_response'])) {
			$errorMessage = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_payment_form_error');
			// Setting up the error message in the shop variable 
			$alertHelper = Shop::Container()->getAlertService();        
			$alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessage, 'display error on payment page', ['saveInSession' => true]);        
			
			// Redirecting to the checkout page 
			\header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
			exit;
		}
            
        // Handle the payment process based on the payment form chosen payment method
        if ($_SESSION['novalnet']['seamless_payment_form_response']['result']['status'] != 'SUCCESS') {
            $this->novalnetPaymentGateway->redirectOnError($order, $_SESSION['novalnet']['seamless_payment_form_response'], $_SESSION['novalnet']['payment_key']);
        } else {
            $orderHash = $this->generateHash($order);
            
            // Collecting the payment parameters to initiate the call to the Novalnet server 
            $paymentRequestData = $this->novalnetPaymentGateway->generatePaymentParams($order);
            
            // Send the payment mode is in LIVE or TEST
             $paymentRequestData['transaction']['test_mode'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['test_mode'];
             
            // Payment type included to notify the server 
            $paymentRequestData['transaction']['payment_type'] = $paymentType;
            
            // Sending the specific parameters to the server for requested payment methods
            $this->novalnetPaymentGateway->getMandatoryPaymentParameters($paymentRequestData);
            
            // Sending cart details to Paypal payment
            if ($_SESSION['novalnet']['payment_key'] == 'paypal') {
                $paymentRequestData['cart_info'] = $this->novalnetPaymentGateway->getBasketDetails(true);
            }
            
            // Checking if the payment has authorization is in place or immediate capture 
            $_SESSION['novalnet']['payment_url'] = (isset($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action']) && $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action'] == 'authorized') ? 'authorize' : 'payment';
            
            // If the Card processing requires a 3D authentication from the consumer or if it redirection payment, we redirect 
            if (!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['do_redirect']) || $_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['process_mode'] == 'redirect') {
                // Setting up the return URL for the success / error message information (the landing page after customer redirecting back from partner)
                $paymentRequestData['transaction']['return_url']   = ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) ? $this->getNotificationURL($orderHash) : $this->getNotificationURL($orderHash).'&sh=' . $orderHash;
            
                // Do the payment call to Novalnet server
                $paymentResponseData = $this->novalnetPaymentGateway->performServerCall($paymentRequestData, $_SESSION['novalnet']['payment_url']);
            }
            
            $_SESSION['novalnet']['payment_request'] = $paymentRequestData;
            // Do redirect if the redirect URL is present
            if (!empty($paymentResponseData['result']['redirect_url']) && !empty($paymentResponseData['transaction']['txn_secret'])) {  

                // Transaction secret used for the later checksum verification
                $_SESSION[$_SESSION['novalnet']['payment_key']]['novalnet_txn_secret'] = $paymentResponseData['transaction']['txn_secret'];
                
                \header('Location: ' . $paymentResponseData['result']['redirect_url']);
                exit;
            } else {
            
                if($this->duringCheckout == 0) {
                    
                    // Do the payment call to Novalnet server when payment before order completion option is set to 'Nein'            
                    $_SESSION['novalnet']['payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['novalnet']['payment_request'], $_SESSION['novalnet']['payment_url']);
                    
                    $this->novalnetPaymentGateway->validatePaymentResponse($order, $_SESSION['novalnet']['payment_response'], $_SESSION['novalnet']['payment_key']);
                    // If the payment is done after order completion process
                    \header('Location:' . $this->getNotificationURL($orderHash));
                    exit;
                    
                } else {
                    // If the payment is done during ordering process
                    \header('Location:' . $this->getNotificationURL($orderHash) . '&sh=' . $orderHash);
                    exit;
                }
            }
        }
    }
    
    /**
     * Called on notification URL
     *
     * @param  Bestellung $order
     * @param  string     $hash
     * @param  array      $args
     * @return bool
     */
    public function finalizeOrder(Bestellung $order, string $hash, array $args): bool
    {
        if (!empty($args['txn_secret'])) {
            // Checksum validation for redirects
            if (!empty($args['tid'])) {
                // Checksum verification and transaction status call to retrieve the full response
                $paymentResponseData = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($order, $args, $_SESSION['novalnet']['payment_key']);
                $_SESSION['novalnet']['payment_response'] = $paymentResponseData;
                
                // Evaluating the payment response for the redirected payment
                return $this->novalnetPaymentGateway->validatePaymentResponse($order, $paymentResponseData, $_SESSION['novalnet']['payment_key']);
            } else {
                $this->novalnetPaymentGateway->redirectOnError($order, $args, $_SESSION['novalnet']['payment_key']);
            }
        } else {
            // Do the payment call to Novalnet server when payment before order completion option is set to 'Nein'        
            $_SESSION['novalnet']['payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['novalnet']['payment_request'], $_SESSION['novalnet']['payment_url']);
        
            return $this->novalnetPaymentGateway->validatePaymentResponse($order, $_SESSION['novalnet']['payment_response'], $_SESSION['novalnet']['payment_key']);
        }
    }
    
    /**
     * Called when order is finalized and created on notification URL
     *
     * @param  Bestellung $order
     * @param  string     $hash
     * @param  array      $args
     * @return none
     */
    public function handleNotification(Bestellung $order, string $hash, array $args): void
    {   
        // Set the cashpayment token to session         
        if (isset($_SESSION['novalnet']['payment_response']) && $_SESSION['novalnet']['payment_response']['result']['status'] == 'SUCCESS' && isset($_SESSION['novalnet']['payment_response']['transaction']['checkout_token'])) {
            $_SESSION['novalnet_cashpayment_token'] = $_SESSION['novalnet']['payment_response']['transaction']['checkout_token'];
            $_SESSION['novalnet_cashpayment_checkout_js']  = $_SESSION['novalnet']['payment_response']['transaction']['checkout_js'];
        }
        
        $referenceTid = !empty($args['tid']) ? $args['tid'] : 0;
        // Confirming if there is problem in synchronization and there is a payment entry already
        $incomingPayment = Shop::Container()->getDB()->select('tzahlungseingang', ['kBestellung', 'cHinweis'], [$order->kBestellung, $referenceTid]);
        if (is_object($incomingPayment) && intval($incomingPayment->kZahlungseingang) > 0) {
            $this->novalnetPaymentGateway->completeOrder($order, $_SESSION['novalnet']['payment_key'], $this->generateHash($order));
        } else {
            if (isset($_SESSION['Zahlungsart']->nWaehrendBestellung) && $_SESSION['Zahlungsart']->nWaehrendBestellung == 0 && !empty($referenceTid)) {
                // Checksum verification and transaction status call to retrieve the full response
                $paymentResponseData = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($order, $args, $_SESSION['novalnet']['payment_key']);
                $_SESSION['novalnet']['payment_response'] = $paymentResponseData;
            }
            // Adds the payment method into the shop table and change the order status
            $this->updateShopDatabase($order);
        }
    }
    
    /**
     * Adds the payment method into the shop table, updates notification ID and set the order status
     *
     * @param  object $order
     * @return none
     */
    public function updateShopDatabase(object $order): void
    {           
        $isTransactionPaid = '';

        // Add the incoming payments if the transaction was confirmed
        if ($_SESSION['novalnet']['payment_response']['transaction']['status'] == 'CONFIRMED' && !in_array($_SESSION['novalnet']['payment_key'], array('invoice', 'prepayment', 'cashpayment', 'multibanco')) && $_SESSION['novalnet']['payment_response']['transaction']['amount'] != 0) {
            $incomingPayment           = new stdClass();
            $incomingPayment->fBetrag  = $order->fGesamtsummeKundenwaehrung;
            $incomingPayment->cISO     = $order->Waehrung->cISO;
            $incomingPayment->cHinweis = $_SESSION['novalnet']['payment_response']['transaction']['tid'];
            $this->name                = $order->cZahlungsartName;
            
            // Add the current transaction payment into db
            $this->addIncomingPayment($order, $incomingPayment);  
            
            // Update the payment paid time to the shop order table
            $isTransactionPaid = true;
        }
        
        // Updates transaction ID into shop for reference
        $this->updateNotificationID($order->kBestellung, (string) $_SESSION['novalnet']['payment_response']['transaction']['tid']); 
        
        // Getting the transaction comments to store it in the order 
        $transactionDetails = $this->novalnetPaymentGateway->getTransactionInformation($order, $_SESSION['novalnet']['payment_response'], $_SESSION['novalnet']['payment_key']);
        
        // Setting up the Order Comments and Order status in the order table 
        $orderStatus = ($_SESSION['novalnet']['payment_response']['transaction']['status'] == 'PENDING') ? \BESTELLUNG_STATUS_OFFEN : ($_SESSION['novalnet']['payment_response']['transaction']['status'] == 'ON_HOLD' ? constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status')) : \BESTELLUNG_STATUS_BEZAHLT);
        
        // Set the ON-HOLD order status for zero amount booking transactions
        if(isset($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action']) && $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action'] == 'zero_amount') {
			$orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status'));
		}
		
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $order->cBestellNr, ['cStatus' => $orderStatus, 'cKommentar' =>  $transactionDetails, 'dBezahltDatum' => ($isTransactionPaid ? 'NOW()' : '')]); 
        
        // Completing the order based on the resultant status 
		$this->novalnetPaymentGateway->handlePaymentCompletion($order, $_SESSION['novalnet']['payment_key'], $this->generateHash($order), $transactionDetails);  
		
    }
}
