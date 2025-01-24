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
 * Script: NovalnetMultibanco.php
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
 * Class NovalnetMultibanco
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetMultibanco extends Method
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
    private $paymentName = 'novalnet_multibanco';
    
    /**
     * NovalnetMultibanco constructor.
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

        $this->name    = 'Novalnet Multibanco';
        $this->caption = 'Novalnet Multibanco';

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
        $paymentRequestData['transaction']['payment_type'] = 'MULTIBANCO';
        
        $_SESSION['nn_'. $this->paymentName . '_request'] = $paymentRequestData;
        
        if ($this->duringCheckout == 0) {
            
            // Do the payment call to Novalnet server when payment before order completion option is set to 'Nein'            
            $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['nn_'. $this->paymentName . '_request'], 'payment');
            
            $this->novalnetPaymentGateway->validatePaymentResponse($order, $_SESSION['nn_'. $this->paymentName .'_payment_response'], $this->paymentName);
            // If the payment is done after order completion process
            \header('Location:' . $this->getNotificationURL($orderHash));
            exit;
            
        } else {
            // If the payment is done during ordering process
            \header('Location:' . $this->getNotificationURL($orderHash) . '&sh=' . $orderHash);
            exit;
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
        // Do the payment call to Novalnet server when payment before order completion option is set to 'Nein'        
        $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['nn_'. $this->paymentName . '_request'], 'payment');
        
        return $this->novalnetPaymentGateway->validatePaymentResponse($order, $_SESSION['nn_'. $this->paymentName .'_payment_response'], $this->paymentName);
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
        // Adds the payment method into the shop table and change the order status
        $this->updateShopDatabase($order);
        
    }
    
    /**
     * Adds the payment method into the shop table, updates notification ID and set the order status
     *
     * @param  object $order
     * @return none
     */
    public function updateShopDatabase(object $order): void
    {                
        // Updates transaction ID into shop for reference
        $this->updateNotificationID($order->kBestellung, (string) $_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['tid']); 
        
        // Getting the transaction comments to store it in the order 
        $transactionDetails = $this->novalnetPaymentGateway->getTransactionInformation($order, $_SESSION['nn_'.$this->paymentName.'_payment_response'], $this->paymentName);
        
        // Collecting the Multibanco reference details required for the payment method
        $transactionDetails .= $this->getMultibancoReferenceInformation($order, $_SESSION['nn_'.$this->paymentName.'_payment_response']);       
        
        // Setting up the Order Comments and Order status in the order table 
        $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->paymentName));
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $order->cBestellNr, ['cStatus' => $orderStatus, 'cKommentar' =>  $transactionDetails]);
        
        // Completing the order based on the resultant status 
        $this->novalnetPaymentGateway->handlePaymentCompletion($order, $this->paymentName, $this->generateHash($order), $transactionDetails);
    }
    
    /**
     * Setting up the Multibanco reference details for storing in the order 
     *
     * @param object $order 
     * @param array $paymentResponse     
     * @return string
     */
    private function getMultibancoReferenceInformation(object $order, array $paymentResponse): string
    {
        $multibancoComments  = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_multibanco_reference_text'), number_format($paymentResponse['transaction']['amount'] / 100 , 2, ',', ''), $order->Waehrung->htmlEntity);
        $multibancoComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_multibanco_reference_one') . $paymentResponse['transaction']['partner_payment_reference'];
        $multibancoComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_multibanco_reference_two') . $paymentResponse['transaction']['service_supplier_id'];
        
        return $multibancoComments;
    }
}
