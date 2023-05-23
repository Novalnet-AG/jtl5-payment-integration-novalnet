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
 * Script: NovalnetPaypal.php
 *
 */

namespace Plugin\jtl_novalnet\paymentmethod;

use JTL\Plugin\Payment\Method;
use JTL\Checkout\Bestellung;
use JTL\Shop;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;
use JTL\Checkout\ZahlungsLog;
use JTL\Session\Frontend;
use JTL\Customer\Customer;
use stdClass;

/**
 * Class NovalnetPaypal
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetPaypal extends Method
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
     * @var string
     */
    private $paymentName = 'novalnet_paypal';
    
    /**
     * NovalnetPaypal constructor.
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
     * @param int $nAgainCheckout
     * @return $this
     */
    public function init(int $nAgainCheckout = 0): self
    {
        parent::init($nAgainCheckout);

        $this->name    = 'Novalnet PayPal';
        $this->caption = 'Novalnet PayPal';

        return $this;
    }
    
    /**
     * Check the payment condition for displaying the payment on payment page
     * 
     * @param array $args_arr
     * @return bool
     */
    public function isValidIntern(array $args_arr = []): bool
    {       
        return $this->novalnetPaymentGateway->canPaymentMethodProcessed($this->paymentName);
    }
    
    /**
     * Initiates the Payment process
     * 
     * @param  object $order
     * @return none|bool
     */
    public function preparePaymentProcess(Bestellung $order): void
    {
        $orderHash = $this->generateHash($order);
        
        // Collecting the payment parameters to initiate the call to the Novalnet server 
        $paymentRequestData = $this->novalnetPaymentGateway->generatePaymentParams($order, $this->paymentName);
        
        // Payment type included to notify the server 
        $paymentRequestData['transaction']['payment_type'] = 'PAYPAL';              
        
        // If the consumer has opted to pay with the saved account data, we use the token relavant to that      
        if (!empty($_SESSION[$this->paymentName]['nn_customer_selected_token'])) {
            // Selected token is the key to the stored payment data             
            $paymentRequestData['transaction']['payment_data']['token'] = $_SESSION[$this->paymentName]['nn_customer_selected_token'];      
        } else {        
            // If the consumer has opted to save the account data for future purchases, we notify the server
            if (!empty($_SESSION[$this->paymentName]['nn_save_payment_data'])) {
                $paymentRequestData['transaction']['create_token'] = 1;
            }                       
            
            // Setting up the return URL for the success / error message information (the landing page after customer redirecting back from partner)
            $paymentRequestData['transaction']['return_url']   = ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) ? $this->getNotificationURL($orderHash) : $this->getNotificationURL($orderHash).'&sh=' . $orderHash;
        }
        
        // Checking if the payment type has authorization is in place or immediate capture 
        $paymentRequestData['payment_url'] = !empty($this->novalnetPaymentGateway->isTransactionRequiresAuthorizationOnly($paymentRequestData['transaction']['amount'], $this->paymentName)) ? 'authorize' : 'payment';
        
        // Do the payment call to Novalnet server
        $paymentResponseData = $this->novalnetPaymentGateway->performServerCall($paymentRequestData, $paymentRequestData['payment_url']);
            
        // Do redirect if the redirect URL is present
        if (($paymentResponseData['result']['redirect_url'] != '') && ($paymentResponseData['transaction']['txn_secret']) != '') {  

            // Transaction secret used for the later checksum verification
            $_SESSION[$this->paymentName]['novalnet_txn_secret'] = $paymentResponseData['transaction']['txn_secret'];
            
            \header('Location: ' . $paymentResponseData['result']['redirect_url']);
            exit;
        } else {
            if ($this->duringCheckout == 0) {
            
                $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $paymentResponseData;
                
                $this->novalnetPaymentGateway->validatePaymentResponse($order, $_SESSION['nn_'. $this->paymentName .'_payment_response'], $this->paymentName);
                // If the payment is done after order completion process
                \header('Location:' . $this->getNotificationURL($orderHash));
                exit;
                
            } else {
                // If the payment is done during ordering process
                \header('Location:' . $this->getNotificationURL($orderHash) . '&sh=' . $orderHash);
                exit;
            }       
            
            $this->novalnetPaymentGateway->redirectOnError($order, $paymentResponseData, $this->paymentName);
        }
    }
    
    /**
     * Called on notification URL
     *
     * @param  object $order
     * @param  string $hash
     * @param  array  $args
     * @return bool
     */
    public function finalizeOrder(Bestellung $order, string $hash, array $args): bool
    {
        // Checksum validation for redirects
        if (!empty($args['tid'])) {
            // Checksum verification and transaction status call to retrieve the full response
            $paymentResponseData = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($order, $args, $this->paymentName);
            $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $paymentResponseData;
            
            // Evaluating the payment response for the redirected payment
            return $this->novalnetPaymentGateway->validatePaymentResponse($order, $paymentResponseData, $this->paymentName);
        } else {
            $this->novalnetPaymentGateway->redirectOnError($order, $args, $this->paymentName);
        }
    }
    
    /**
     * Called when order is finalized and created on notification URL
     *
     * @param  object $order
     * @param  string $hash
     * @param  array  $args
     * @return none
     */
    public function handleNotification(Bestellung $order, string $hash, array $args): void
    {
        // Confirming if there is problem in synchronization and there is a payment entry already
        $incomingPayment = Shop::Container()->getDB()->select('tzahlungseingang', ['kBestellung', 'cHinweis'], [$order->kBestellung, $args['tid']]);
        if (is_object($incomingPayment) && intval($incomingPayment->kZahlungseingang) > 0) {
            $this->novalnetPaymentGateway->completeOrder($order, $this->paymentName, $this->generateHash($order));
        } else {
            if (isset($_SESSION['Zahlungsart']->nWaehrendBestellung) && $_SESSION['Zahlungsart']->nWaehrendBestellung == 0 && !empty($args['tid'])) {
                // Checksum verification and transaction status call to retrieve the full response
                $paymentResponseData = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($order, $args, $this->paymentName);
                $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $paymentResponseData;
            }
            // Adds the payment method into the shop table and change the order status
            $this->updateShopDatabase($order);
        
            // Completing the order based on the resultant status 
            $this->novalnetPaymentGateway->handlePaymentCompletion($order, $this->paymentName, $this->generateHash($order));            
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
        if ($_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['status'] == 'CONFIRMED') {
            $incomingPayment           = new stdClass();
            $incomingPayment->fBetrag  = $order->fGesamtsummeKundenwaehrung;
            $incomingPayment->cISO     = $order->Waehrung->cISO;
            $incomingPayment->cHinweis = $_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['tid'];
            $this->name                = $order->cZahlungsartName;
            
            // Add the current transaction payment into db
            $this->addIncomingPayment($order, $incomingPayment);  
            
            // Update the payment paid time to the shop order table
            $isTransactionPaid = true;
        }
        
        // Updates transaction ID into shop for reference
        $this->updateNotificationID($order->kBestellung, (string) $_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['tid']); 
        
        // Getting the transaction comments to store it in the order 
        $transactionDetails = $this->novalnetPaymentGateway->getTransactionInformation($order, $_SESSION['nn_'.$this->paymentName.'_payment_response'], $this->paymentName);    
        
        // Setting up the Order Comments and Order status in the order table 
        $orderStatus = ($_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['status'] == 'PENDING' ? \BESTELLUNG_STATUS_OFFEN : ($_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['status'] == 'ON_HOLD' ? constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status')) : constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->paymentName))));
        
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $order->cBestellNr, ['cStatus' => $orderStatus, 'cKommentar' =>  $transactionDetails, 'dBezahltDatum' => ($isTransactionPaid ? 'NOW()' : '')]); 
    }
}
