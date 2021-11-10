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
 * Script: NovalnetWebhookHandler.php
 *
*/

namespace Plugin\jtl_novalnet;

use JTL\Shop;
use JTL\Plugin\Payment\Method;
use JTL\Checkout\Bestellung;
use JTL\Session\Frontend;
use JTL\Helpers\Request;
use JTL\DB\ReturnType;
use PHPMailer\PHPMailer\PHPMailer;
use JTL\Mail\Mail\Mail;
use JTL\Mail\Mailer;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;
use JTL\Catalog\Product\Preise;
use stdClass;

/**
 * Class NovalnetWebhookHandler
 * @package Plugin\jtl_novalnet
 */
class NovalnetWebhookHandler
{
    /**
     * @var eventData
     */
    protected $eventData = array();

    /**
     * @var eventType
     */
    protected $eventType;

    /**
     * @var eventTid
     */
    protected $eventTid;
        
    /**
     * @var parentTid
     */
    protected $parentTid;
    
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;
    
    /**
     * @var object
     */
    private $orderDetails;
    
    /**
     * @var string
     */
    private $languageCode;
    
    /**
     * NovalnetWebhookHandler constructor.
     */
    public function __construct()
    {
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();
    }
    
    public function handleNovalnetWebhook() 
    {
        try {
            $this->eventData = json_decode(file_get_contents('php://input'), true);
        } catch (Exception $e) {
            $this->displayMessage('Received data is not in the JSON format' . $e);
        }
        
        // validated the IP Address
        $this->validateIpAddress();

        // Validates the webhook params before processing
        $this->validateEventParams();
        
        // Set Event data
        $this->eventType = $this->eventData['event']['type'];
        $this->parentTid = !empty($this->eventData['event']['parent_tid']) ? $this->eventData['event']['parent_tid'] :$this->eventData['event']['tid'];
        $this->eventTid  = $this->eventData['event']['tid'];
        
        // Retreiving the shop's order information based on the transaction 
        $this->orderDetails = $this->getOrderReference();
        
        $this->languageCode = ($this->orderDetails->kSprache == 1) ? 'ger' : 'eng';
                
        if ($this->eventData['result']['status'] == 'SUCCESS') {
            
            switch ($this->eventType) {
                case 'PAYMENT':
                    $this->displayMessage('The Payment has been received');
                    break;
                case 'TRANSACTION_CAPTURE':
                case 'TRANSACTION_CANCEL':
                    $this->handleNnTransactionCaptureCancel();
                    break;
                case 'TRANSACTION_UPDATE':
                    $this->handleNnTransactionUpdate();
                    break;
                case 'TRANSACTION_REFUND':
                    $this->handleNnTransactionRefund();
                    break;
                case 'CREDIT':
                    $this->handleNnTransactionCredit();
                    break;
                case 'CHARGEBACK':
                    $this->handleNnChargeback();
                    break;
                case 'INSTALMENT':
                    $this->handleNnInstalment();
                    break;
                case 'INSTALMENT_CANCEL':
                    $this->handleNnInstalmentCancel();
                    break;
                default:
                    $this->displayMessage('The webhook notification has been received for the unhandled EVENT type ( ' . $this->eventType . ')' );
            }
        }
    }
    
    /**
     * Display webhook notification message 
     *
     * @param string $message
     * @return none
     */
    public function displayMessage($message): void
    {
        print $message;
        exit;
    }
    
    /**
     * Validate the IP control check
     *
     * @return none
     */
    public function validateIpAddress(): void
    {
        $realHostIp = gethostbyname('pay-nn.de');
        
        if (empty($realHostIp)) {
            $this->displayMessage('Novalnet HOST IP missing');
        }
        
        // Condition to check whether the callback is called from authorized IP
        if ((Request::getRealIP() != $realHostIp) && empty($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_webhook_testmode'))) {
            $this->displayMessage('Unauthorised access from the IP ' . Request::getRealIP());
        }
    }
    
    /**
     * Validates the event parameters
     *
     * @return none
     */
    public function validateEventParams()
    {
        // Mandatory webhook params
        $requiredParams = ['event' => ['type', 'checksum', 'tid'], 'result' => ['status']];
                                
        // Validate required parameters
        foreach ($requiredParams as $category => $parameters) {
            if (empty($this->eventData[$category])) {
                // Could be a possible manipulation in the notification data
                $this->displayMessage('Required parameter category(' . $category. ') not received');
            } elseif (!empty($parameters)) {
                foreach ($parameters as $parameter) {
                    if (empty($this->eventData[$category][$parameter])) {
                       // Could be a possible manipulation in the notification data
                       $this->displayMessage('Required parameter(' . $parameter . ') in the category (' . $category . ') not received');
                    }
                }
            }
        }

        // Validate the received checksum.
        $this->validateChecksum();
    }
    
    /**
     * Validate checksum
     *
     * @return none
     */
    public function validateChecksum()
    {
        $accessKey = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key'); 
        $tokenString  = $this->eventData['event']['tid'] . $this->eventData['event']['type'] . $this->eventData['result']['status'];
        
        if (isset($this->eventData['transaction']['amount'])) {
            $tokenString .= $this->eventData['transaction']['amount'];
        }
        
        if (isset($this->eventData['transaction']['currency'])) {
            $tokenString .= $this->eventData['transaction']['currency'];
        }
        
        if (!empty($accessKey)) {
            $tokenString .= strrev($accessKey);
        }
        
        $generatedChecksum = hash('sha256', $tokenString);      
        if ($generatedChecksum !== $this->eventData['event']['checksum']) {
            $this->displayMessage('While notifying some data has been changed. The hash check failed');
        }
    }
    
    /**
     * Get order details from the shop's database
     *
     * @return object
     */
    public function getOrderReference(): object
    {
        // Looking into the Novalnet database if the transaction exists
        $novalnetOrder = Shop::Container()->getDB()->query('SELECT cNnorderid, cZahlungsmethode, cStatuswert, nBetrag, cSaveOnetimeToken, cTokenInfo FROM xplugin_novalnet_transaction_details WHERE nNntid = "' . $this->parentTid . '"',  ReturnType::SINGLE_OBJECT);
        
        // If both the order number from Novalnet and in shop is missing, then something is wrong
        if (empty($this->eventData['transaction']['order_no']) && empty($novalnetOrder->cNnorderid)) {
            $this->displayMessage('Order reference not found for the TID ' . $this->parentTid);
        }
        
        $orderNumberToSearch = !empty($novalnetOrder->cNnorderid) ? $novalnetOrder->cNnorderid : $this->eventData['transaction']['order_no'];
            
        // If the order in the Novalnet server to the order number in Novalnet database doesn't match, then there is an issue
        if (!empty($this->eventData['transaction']['order_no']) && !empty($novalnetOrder->cNnorderid) && (($this->eventData['transaction']['order_no']) != $novalnetOrder->cNnorderid)) {
            $this->displayMessage('Order reference not matching for the order number ' . $orderNumberToSearch);
        }        
                
        $shopOrder = Shop::Container()->getDB()->query('SELECT kBestellung FROM tbestellung WHERE cBestellNr = "' . $orderNumberToSearch . '"', ReturnType::SINGLE_OBJECT);
            
        // Loads order object from shop 
        $order = new Bestellung((int) $shopOrder->kBestellung); 
        $order->fuelleBestellung(true, 0, false);
            
        // If the order is not found in Novalnet's database, it is communication failure
        if (empty($novalnetOrder->cNnorderid)) {
          
            // We look into the shop's core database to create the order object for further handling
            if (!empty($shopOrder->kBestellung)) {
                // Handles communication failure scenario
                $this->handleCommunicationBreak($order); 
            } else {
                $this->displayMessage('Transaction mapping failed ' . $orderNumberToSearch);        
            }
        }
        
        return (object) array_merge((array) $order, (array) $novalnetOrder);
    }
    
    /**
     * Handling communication breakup
     *
     * @param object $order
     * @return none
     */
    public function handleCommunicationBreak(object $order): void
    {      
        $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
        
        $orderLanguage = ($order->kSprache == 1) ? 'ger' : 'eng';
        
        $webhookComments  = '';
        
        $webhookComments .= $order->cZahlungsartName;
        
        $webhookComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_transaction_tid', $orderLanguage) . $this->parentTid;
            
        if (!empty($this->eventData['transaction']['test_mode'])) {
            $webhookComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_test_order', $orderLanguage);
        }
        
        $isPaidNow = false;
        
        // Deactivating the WAWI synchronization 
        $updateWawi = 'Y';    
        
        // Setting the payment Name
        if (in_array($this->eventData['transaction']['payment_type'], array('DIRECT_DEBIT_SEPA', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'))) {
                $paymentName = str_replace('DIRECT_DEBIT_', '', $this->eventData['transaction']['payment_type']);
        } else {
            $paymentName = (($this->eventData['transaction']['payment_type'] == 'CREDITCARD') ? 'CC' : (($this->eventData['transaction']['payment_type'] == 'ONLINE_TRANSFER') ? 'SOFORT' : $this->eventData['transaction']['payment_type']));
        }          
        
        if ($this->eventData['result']['status'] == 'SUCCESS') {
            
            if ($this->eventData['transaction']['status']) {    
                                            
                if ($this->eventData['transaction']['status'] == 'PENDING') {
                    $orderStatus = \BESTELLUNG_STATUS_OFFEN;                    
                } elseif ($this->eventData['transaction']['status'] == 'ON_HOLD') {
                    $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status'));
                } elseif ($this->eventData['transaction']['status'] == 'CONFIRMED') {
                    // For the successful case, we enable the WAWI synchronization
                    $updateWawi = 'N';                  
                    $isPaidNow = true;

                    $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', strtolower('NOVALNET_'.$paymentName)));
                    
                    // Add the current transaction payment into db
                    $incomingPayment           = new stdClass();
                    $incomingPayment->fBetrag  = $order->fGesamtsummeKundenwaehrung;
                    $incomingPayment->cISO     = $order->Waehrung->cISO;
                    $incomingPayment->cHinweis = $this->parentTid;
                    $jtlPaymentmethod->name    = $order->cZahlungsartName;
                
                    $jtlPaymentmethod->addIncomingPayment($order, $incomingPayment);                            
                } else {                
                    $orderStatus = \BESTELLUNG_STATUS_STORNO;
                     $webhookComments .= \PHP_EOL . $this->eventData['result']['status_text'];
                }                               
            }
            
            // Sending the order update mail here as due to the communcation failure, the update mail would not have reached
            $jtlPaymentmethod->sendMail($order->kBestellung, MAILTEMPLATE_BESTELLUNG_AKTUALISIERT);
                                
        } else {
            $orderStatus = \BESTELLUNG_STATUS_STORNO;
            $webhookComments .= \PHP_EOL . $this->eventData['result']['status_text'];
        }         
            
        // Entering the details into the Novalnet's transactions details for consistency and further operations
        $this->novalnetPaymentGateway->insertOrderDetailsIntoNnDb($order, $this->eventData, strtolower('NOVALNET_'.$paymentName));               
        
        // Completing the callback notification 
        $this->webhookFinalprocess($webhookComments, $orderStatus, $updateWawi, $isPaidNow);
    }
    
    /**
     * Handling the Novalnet transaction authorization process
     *
     * @return none
     */
    public function handleNnTransactionCaptureCancel(): void
    {
        // Capturing or cancellation of a transaction occurs only when the transaction is found as ON_HOLD in the shop
        if (in_array($this->orderDetails->cStatuswert, array('PENDING', 'ON_HOLD'))) {       

            $isPaidNow = false;                 
            
            // If the transaction is captured, we update necessary alterations in DB        
            if ($this->eventType == 'TRANSACTION_CAPTURE') {               
            
                // Activating the WAWI synchronization 
                $updateWawi = 'N';
            
                $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_confirmation_text', $this->languageCode), date('d.m.Y'), date('H:i:s'));
                
                $amount = (strpos($this->eventData['transaction']['payment_type'], 'INSTALMENT') !== false) ? $this->eventData['instalment']['cycle_amount'] : $this->eventData['transaction']['amount'];
              
                if ($this->eventData['transaction']['payment_type'] == 'PAYPAL') {
                    $tokenInfo = !empty($this->orderDetails->cTokenInfo) ?  json_decode($this->orderDetails->cTokenInfo, true) : '';
                    $this->eventData['transaction']['payment_data']['token'] = $tokenInfo['token'];
                }
                
                // Certain payment methods require their tokens to be stored during capture 
                if ($this->orderDetails->cSaveOnetimeToken == 1 && (empty($this->orderDetails->cTokenInfo) || !empty($tokenInfo))) {                    
                    $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cTokenInfo' => json_encode($this->eventData['transaction']['payment_data'])]);
                }  
                
                // Instalment payment methods cycle information  
                if (!empty($this->eventData['instalment'])) { 
                    $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode($this->eventData['instalment'])]);
                }   
                                                                                    
                                                
                // Only for the Invoice Payment type, we have to wait for the credit entry 
                if ($this->eventData['transaction']['payment_type'] != 'INVOICE') {
                    // Loads the order object
                    $order = new Bestellung((int) ($this->orderDetails->kBestellung)); 
                    $order->fuelleBestellung(true, 0, false);

                    // Add the incoming payments if the transaction was confirmed
                    $jtlPaymentmethod = Method::create($this->orderDetails->Zahlungsart->cModulId);
                    
                    $incomingPayment           = new stdClass();
                    $incomingPayment->fBetrag  = $this->orderDetails->fGesamtsummeKundenwaehrung;
                    $incomingPayment->cISO     = $this->orderDetails->Waehrung->cISO;
                    $incomingPayment->cHinweis = $this->parentTid;
                    $jtlPaymentmethod->name                = $this->orderDetails->cZahlungsartName;
                    
                    // Add the current transaction payment into db
                    $jtlPaymentmethod->addIncomingPayment($order, $incomingPayment);  
                    
                    $isPaidNow = true;                    
                }
               
                // Order status required to be changed for the payment type 
                $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->orderDetails->cZahlungsmethode));
                
                                                                 
            } elseif($this->eventType == 'TRANSACTION_CANCEL') {
                                            
                $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_transaction_cancellation', $this->languageCode), date('d.m.Y'), date('H:i:s'));
                
                $orderStatus = \BESTELLUNG_STATUS_STORNO;  

                // We do not want that cancelled orders picked up in WAWI 
                $updateWawi = 'Y';
            }    
            
            $this->eventData['transaction']['amount'] = ($this->eventData['transaction']['payment_type'] == 'INVOICE') ? 0 : $this->eventData['transaction']['amount'];                                      
           
            $this->webhookFinalprocess($webhookComments, $orderStatus, $updateWawi, $isPaidNow);
            
        } else {
            $this->displayMessage('Transaction already captured/cancelled');
        }
    }
    
    /**
     * Handling the Novalnet transaction update process
     *
     * @return none
     */
    public function handleNnTransactionUpdate(): void
    {  
        // Paid is set to NULL
        $isPaidNow = false;
        
        // Set the WAWI status
        $updateWawi = ($this->eventData['transaction']['status'] == 'CONFIRMED') ? 'N' : 'Y';
        
        if ($this->eventData['transaction']['update_type'] == 'STATUS') {
            
            if ($this->eventData['transaction']['status'] == 'DEACTIVATED') {
                        
                $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_transaction_cancellation', $this->languageCode), date('d.m.Y'), date('H:i:s'));
                
                // Cancellation of the order status 
                $orderStatus = \BESTELLUNG_STATUS_STORNO;
            
            } else {
                
                if ($this->orderDetails->cStatuswert == 'PENDING' && in_array($this->eventData['transaction']['status'], ['ON_HOLD', 'CONFIRMED'])) {
                
                    $webhookComments = '';
                
                    if (in_array($this->eventData['transaction']['payment_type'], ['INVOICE', 'PREPAYMENT', 'GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
                        $webhookComments = $this->novalnetPaymentGateway->getBankdetailsInformation($this->orderDetails, $this->eventData, $this->languageCode);
                    }
                    
                    // For on-hold, we only update the order status 
                    if ($this->eventData['transaction']['status'] == 'ON_HOLD') {
                        // Building the onhold activation text 
                        $webhookComments .= \PHP_EOL . \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_pending_to_onhold_status_change', $this->languageCode), $this->parentTid, date('d.m.Y'), date('H:i:s'));
                        
                        $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status'));
                    } else {
                        
                        $amount = (strpos($this->eventData['transaction']['payment_type'], 'INSTALMENT') !== false) ? $this->eventData['instalment']['cycle_amount'] : $this->eventData['transaction']['amount'];
                        
                        // Building the confirmation text 
                        $webhookComments .= \PHP_EOL . \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_update_confirmation_text', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor( $amount / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'));
                        
                        // Instalment payment methods cycle information  
                        if (!empty($this->eventData['instalment'])) { 
                            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode($this->eventData['instalment'])]);
                        }
                        
                        $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->orderDetails->cZahlungsmethode)); 
                        
                        if ($this->eventData['transaction']['payment_type'] != 'INVOICE') {
                            // Paid is set to NULL
                            $isPaidNow = true; 
                        }                 
                    }
                }

            }
        } else {
            
            $webhookComments = '';
            
            $dueDateUpdateMessage = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_duedate_update_message', $this->languageCode), Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ), $this->eventData['transaction']['due_date']);

            // Amount update process
            if($this->eventData['transaction']['amount'] != $this->orderDetails->nBetrag && !in_array($this->eventData['transaction']['payment_type'], array('INSTALMENT_INVOICE', 'INSTALMENT_SEPA'))) {
                if($this->eventData['transaction']['amount'] < $this->orderDetails->nBetrag) {
                    $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['nBetrag' => $this->eventData['transaction']['amount']]);
                }
            }
            
            $amountUpdateMessage = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_amount_update_message', $this->languageCode), Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'));
            
            $webhookComments .= (($this->eventData['transaction']['update_type'] == 'AMOUNT') ? $amountUpdateMessage : (($this->eventData['transaction']['update_type'] == 'DUE_DATE') ? $dueDateUpdateMessage : $dueDateUpdateMessage . $amountUpdateMessage));

        }
   
        $this->webhookFinalprocess($webhookComments, $orderStatus, $updateWawi, $isPaidNow);        
    }
    
    /**
     * Handling the transaction refund process
     *
     * @return none
     */
    public function handleNnTransactionRefund(): void
    {
        if (!empty($this->eventData['transaction']['refund']['amount'])) {   
        
            $webhookComments = '';                                  
            
            if (!empty($this->eventData['transaction']['refund']['tid'])) {
                $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_new_tid_refund_execution', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor($this->eventData['transaction']['refund']['amount'] / 100, Frontend::getCurrency(), false ), $this->eventData['transaction']['refund']['tid']);
            } else {
                $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_refund_execution', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor($this->eventData['transaction']['refund']['amount'] / 100, Frontend::getCurrency(), false )); 
            }
            
            // In case of full refund, deactivation is processed 
            if ($this->eventData['transaction']['status'] == 'DEACTIVATED') {
                
                // We do not send to webhookFinalprocess for update as it will cause problems with WAWI synchronization
                $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $this->orderDetails->cBestellNr, ['cStatus' => \BESTELLUNG_STATUS_STORNO]);                               
            }
            
            $this->webhookFinalprocess($webhookComments);
        }    
    }
    
    /**
     * Handling the credit process
     *
     * @return none
     */
    public function handleNnTransactionCredit(): void
    {
        $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_webhook_initial_execution', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'), $this->eventTid);
        
        if (in_array($this->eventData['transaction']['payment_type'], ['INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'ONLINE_TRANSFER_CREDIT', 'MULTIBANCO_CREDIT'])) {
            
            $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->orderDetails->cZahlungsmethode));
            
            $callbackDetails = Shop::Container()->getDB()->query('SELECT SUM(nCallbackAmount) AS kCallbackAmount FROM xplugin_novalnet_callback WHERE cNnorderid = "' . $this->orderDetails->cBestellNr . '" ', ReturnType::SINGLE_OBJECT);

            
            $callbackDetails->kCallbackAmount = !empty($callbackDetails->kCallbackAmount) ? $callbackDetails->kCallbackAmount : 0;
            
            $orderPaidAmount = $callbackDetails->kCallbackAmount + $this->eventData['transaction']['amount'];            
            $orderAmount = !empty($this->orderDetails->nBetrag) ? $this->orderDetails->nBetrag : ($this->orderDetails->fGesamtsumme * 100);
                
                if ($orderPaidAmount >= $orderAmount) {
                    // Loads the order object
                    $order = new Bestellung((int) ($this->orderDetails->kBestellung)); 
                    $order->fuelleBestellung(true, 0, false);
                    
                    // Add the current transaction payment into db
                    $jtlPaymentmethod = Method::create($this->orderDetails->Zahlungsart->cModulId);
                    
                    $incomingPayment           = new stdClass();
                    $incomingPayment->fBetrag  = $this->orderDetails->fGesamtsummeKundenwaehrung;
                    $incomingPayment->cISO     = $this->orderDetails->Waehrung->cISO;
                    $incomingPayment->cHinweis = $this->parentTid;
                    $jtlPaymentmethod->name    = $this->orderDetails->cZahlungsartName;
                    
                    $jtlPaymentmethod->addIncomingPayment($order, $incomingPayment); 
                                       
                    $orderStatus = $this->eventData['transaction']['payment_type'] == 'ONLINE_TRANSFER_CREDIT' ? constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->orderDetails->cZahlungsmethode)) : constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('callback_status', $this->orderDetails->cZahlungsmethode));
                    
                    $this->webhookFinalprocess($webhookComments, $orderStatus, 'N', true);
                    
                } else {
                    $this->webhookFinalprocess($webhookComments, $orderStatus);
                }                                                   
        } else {
            $this->webhookFinalprocess($webhookComments);
        } 
    }
    
    /**
     * Handling the chargeback process
     *
     * @return none
     */
    public function handleNnChargeback(): void
    {
        if ($this->eventData['transaction']['payment_type'] == 'RETURN_DEBIT_SEPA') {
            $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_return_debit_execution_text', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'), $this->eventTid);

        } elseif ($this->eventData['transaction']['payment_type'] == 'REVERSAL') {
            $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_reversal_execution_text', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'), $this->eventTid);
        } else {
            $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_chargeback_execution_text', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'), $this->eventTid);
        }
        
        $this->webhookFinalprocess($webhookComments);
    }
    
    /**
    * Handling the Instalment payment execution
    *
    * @return none
    */
    public function handleNnInstalment(): void
    {
        if ($this->eventData['transaction']['status'] == 'CONFIRMED' && !empty($this->eventData['instalment']['cycles_executed'])) {
            $additionalInstalmentMsg = $nextSepaInstalmentMsg = '';
            
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_payment_execution', $this->languageCode), $this->parentTid, Preise::getLocalizedPriceWithoutFactor( $this->eventData['instalment']['cycle_amount'] / 100, Frontend::getCurrency(), false ), date('d.m.Y'), date('H:i:s'), $this->eventTid);
            
            $additionalInfo = Shop::Container()->getDB()->query('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid = "' . $this->orderDetails->cBestellNr . '"', ReturnType::SINGLE_OBJECT);
           
            $instalmentCyclesInfo = json_decode($additionalInfo->cAdditionalInfo, true);
            $insCycleCount = $this->eventData['instalment']['cycles_executed'];
            $instalmentCyclesInfo[$insCycleCount]['tid'] = $this->eventTid;
            
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode($instalmentCyclesInfo)]);
            
            if (empty($this->eventData['instalment']['prepaid'])) {
                if ($this->eventData['transaction']['payment_type'] == 'INSTALMENT_INVOICE') {
                    $additionalInstalmentMsg = \PHP_EOL . $this->novalnetPaymentGateway->getBankdetailsInformation($this->orderDetails, $this->eventData, $this->languageCode);
                    
                } else {
                    $additionalInstalmentMsg = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_next_instalment_sepa_message', $this->languageCode), Preise::getLocalizedPriceWithoutFactor( $this->eventData['transaction']['amount'] / 100, Frontend::getCurrency(), false ));
                    $nextSepaInstalmentMsg = $additionalInstalmentMsg;
                }
                
            }
            
            $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($this->orderDetails->cBestellNr, $this->languageCode);
            
            $webhookComments .= $nextSepaInstalmentMsg;
            // Send mail notification to customer regarding the new instalment creation
            $this->sendInstalmentMailNotification($instalmentInfo, $additionalInstalmentMsg);
            
            $this->webhookFinalprocess($webhookComments);
        }
    }
    
    /**
     * Handling the Instalment cancelation
     *
     * @return none
     */
    public function handleNnInstalmentCancel(): void
    {
        $webhookComments = sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_cancellation', $this->languageCode), $this->parentTid, date('d.m.Y'), date('H:i:s'));             
        
        $this->webhookFinalprocess($webhookComments, \BESTELLUNG_STATUS_STORNO, 'Y');
    }
    
    /**
     * Performs final callback process
     *
     * @param string  $webhookMessage
     * @param string  $orderStatusToUpdate
     * @param string  $updateWawiStatus
     * @param bool    $isPaidNow
     * @return none
     */
    public function webhookFinalprocess($webhookMessage, $orderStatusToUpdate = '', $updateWawiStatus = 'N', $isPaidNow = false)
    {  
        $oldTransactionComment = Shop::Container()->getDB()->query('SELECT cKommentar, kSprache FROM tbestellung WHERE cBestellNr = "' . $this->orderDetails->cBestellNr . '"', ReturnType::SINGLE_OBJECT);
        
        if (strpos($this->eventData['transaction']['payment_type'], 'INVOICE') !== false) {
            if($this->eventType == 'TRANSACTION_CAPTURE') {
                if (strpos($oldTransactionComment->cKommentar, 'auf das folgende Konto') !== false && $oldTransactionComment->kSprache == 1) {
                    $oldTransactionComment->cKommentar = str_replace('auf das folgende Konto', 'spätestens bis zum ' .$this->eventData['transaction']['due_date'] . ' auf das folgende Konto', $oldTransactionComment->cKommentar);
                } else {
                  $oldTransactionComment->cKommentar = str_replace('to the following account', 'to the following account on or before ' . $this->eventData['transaction']['due_date'] , $oldTransactionComment->cKommentar);   
                }
            }
            
            if(($this->eventType == 'INSTALMENT' || $this->eventType == 'TRANSACTION_UPDATE' && in_array($this->eventData['transaction']['update_type'], array('DUE_DATE', 'AMOUNT_DUE_DATE')))  && (preg_match('/before(.*)/', $oldTransactionComment->cKommentar, $matches) || preg_match('/zum(.*)/', $oldTransactionComment->cKommentar, $matches))) {
                
                    $oldTransactionComment->cKommentar = ($oldTransactionComment->kSprache == 1) ?  str_replace($matches[1], $this->eventData['transaction']['due_date'] . ' auf das folgende Konto', $oldTransactionComment->cKommentar) :  str_replace($matches[0], 'before ' . $this->eventData['transaction']['due_date'] , $oldTransactionComment->cKommentar);
            }
            
                    
            if ($this->eventType == 'INSTALMENT' && preg_match("/[0-9]{17}/", $oldTransactionComment->cKommentar, $matches)) {
                $oldTransactionComment->cKommentar = str_replace($matches[0], $this->eventTid, $oldTransactionComment->cKommentar);
            }
            
           if(($this->eventType == 'INSTALMENT' || $this->eventType == 'TRANSACTION_UPDATE' && in_array($this->eventData['transaction']['update_type'], array('AMOUNT', 'AMOUNT_DUE_DATE')))  && (preg_match('/(.*)to the following/', $oldTransactionComment->cKommentar, $matches) || preg_match('/(.*)spätestens/', $oldTransactionComment->cKommentar, $matches))) {
                $updatedOrderAmount = $this->eventType == 'INSTALMENT' ? $this->eventData['instalment']['cycle_amount'] : 
				$this->eventData['transaction']['amount'];

                $oldTransactionComment->cKommentar = ($oldTransactionComment->kSprache == 1) ? str_replace($matches[1], 'Bitte überweisen Sie den Betrag von ' . number_format($updatedOrderAmount / 100 , 2, ',', '') . ' '. $this->orderDetails->Waehrung->htmlEntity, $oldTransactionComment->cKommentar) :  str_replace($matches[1], 'Please transfer the amount of ' . number_format($updatedOrderAmount / 100 , 2, ',', '') . ' '. $this->orderDetails->Waehrung->htmlEntity , $oldTransactionComment->cKommentar);
            } 
        }
        
        $webhookComments = !empty($oldTransactionComment->cKommentar) ? ($oldTransactionComment->cKommentar . \PHP_EOL . $webhookMessage) : $webhookMessage;
        
        $orderNo = !empty($this->orderDetails->cBestellNr) ? ($this->orderDetails->cBestellNr) : $this->eventData['transaction']['order_no'];
          
        if (!empty($orderStatusToUpdate)) {
            // Updating the necessary details into the core order table related to the webhook transaction
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $orderNo, ['cKommentar' => $webhookComments, 'cStatus' => $orderStatusToUpdate, 'cAbgeholt' => $updateWawiStatus, 'dBezahltDatum' => ($isPaidNow ? 'NOW()' : '')]);
        } else {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $orderNo, ['cKommentar' => $webhookComments, 'cAbgeholt' => $updateWawiStatus, 'dBezahltDatum' => ($isPaidNow ? 'NOW()' : '')]);
        }
        
        // Updating in the Novalnet transaction table
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $orderNo, ['cStatuswert' => $this->eventData['transaction']['status']]);   
        
        // Logging the webhook event notification for further handling and troubleshooting
        $this->logWebhookProcess();
        
        
        // Send the order update mail when the transaction has been confirmed or amount and due date update
        if ($this->eventType == 'TRANSACTION_CAPTURE' || in_array($this->eventData['transaction']['update_type'], array('AMOUNT', 'DUE_DATE', 'AMOUNT_DUE_DATE')) || ($this->eventData['transaction']['update_type'] == 'STATUS' && $this->eventData['transaction']['status'] == 'CONFIRMED')) {
            $jtlPaymentmethod = Method::create($this->orderDetails->Zahlungsart->cModulId);

            $jtlPaymentmethod->sendMail($this->orderDetails->kBestellung, MAILTEMPLATE_BESTELLUNG_AKTUALISIERT);
        
        }
        
        // Send mail for merchant
        $this->sendMailNotification($webhookMessage);
    }
    
    /**
     * To log webhook process into the callback table
     *
     * @return none
     */
    public function logWebhookProcess()
    {        
        $insertCallback = new stdClass();
        $insertCallback->cNnorderid        = $this->orderDetails->cBestellNr;
        $insertCallback->nCallbackTid      = $this->parentTid;
        $insertCallback->nReferenzTid      = $this->eventTid;
        $insertCallback->cZahlungsmethode  = $this->orderDetails->cZahlungsartName;
        $insertCallback->dDatum            = date('Y-m-d H:i:s');
        $insertCallback->nCallbackAmount   = isset($this->eventData['transaction']['amount']) ? $this->eventData['transaction']['amount'] : 0;
        $insertCallback->cWaehrung         = $this->orderDetails->Waehrung->code;

        Shop::Container()->getDB()->insert('xplugin_novalnet_callback', $insertCallback);
    }
    
    /**
     * Triggers mail notification to the mail address specified
     *
     * @param array $webhookMessage
     * @return none
     */
    public function sendMailNotification($webhookMessage)
    {
        // Looping in through the core plugin's mail templates
        foreach ($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getMailTemplates()->getTemplatesAssoc() as $mailTemplate) {
            
            if ($mailTemplate->cModulId == 'novalnetwebhookmail' && $mailTemplate->cAktiv == 'Y') {
                
                $adminDetails = Shop::Container()->getDB()->query('SELECT cMail from tadminlogin LIMIT 1', ReturnType::SINGLE_OBJECT);
                
                // Notification is sent only when the admin login email is configured 
                if (!empty($adminDetails->cMail)) {                 
                
                    $data = new stdClass();
                    $data->webhookMessage = nl2br($webhookMessage); 
                    $data->tkunde = $this->orderDetails->oKunde;       

                    // Constructing the mail object                     
                    $mail = new Mail();
                    $mail->setToMail($adminDetails->cMail);
                    
                    // Replacing the template variable with the webhook message 
                    $mail = $mail->createFromTemplateID('kPlugin_' . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getID() . '_novalnetwebhookmail', $data);
                    
                    // Preparing the shop send email function for dispatching the custom email 
                    $mailer = Shop::Container()->get(Mailer::class);
                    $mailer->send($mail);
                }
                break;
            }
        }
        $this->displayMessage($webhookMessage);
    }
    
    public function sendInstalmentMailNotification(array $instalmentInfo, string $additionalInfo) {
        // Looping in through the core plugin's mail templates
        foreach ($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getMailTemplates()->getTemplatesAssoc() as $mailTemplate) {
            
            if ($mailTemplate->cModulId == 'novalnetinstalmentmail' && $mailTemplate->cAktiv == 'Y') {
                 $instalmentMsg = \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_next_instalment_message', $this->languageCode);
                 
                 $instalmentMsg .= '<br><br>' . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_invoice_payments_order_number_reference', $this->languageCode) . $this->orderDetails->cBestellNr;
                 $instalmentMsg .= '<br>' . $this->orderDetails->cZahlungsartName;

                 $instalmentMsg .= nl2br($additionalInfo);
                
                 $webhookMessage =  '<h3>'.$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_information', $this->languageCode).'</h3>
                    <table id="nn_table">
                       <thead>
                          <style>#nn_table {
                             font-family: Helvetica, Arial, sans-serif;
                             border-collapse: collapse;
                             width: 100%;
                             }
                             #nn_table td, #nn_table th {
                             border: 1px solid #ddd;
                             padding: 8px;
                             }
                             #nn_table tr:nth-child(odd){background-color: #f2f2f2;}
                             #nn_table tr:hover {background-color: #ddd;}
                             #nn_table th {
                             padding-top: 12px;
                             padding-bottom: 12px;
                             text-align: left;
                             background-color: #0080c9;
                             color: white;
                             }
                          </style>
                          <tr>
                             <th>'. $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_serial_no', $this->languageCode). '</th>
                             <th>'.$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_future_date', $this->languageCode).'</th>
                             <th>' .$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_transaction_tid', $this->languageCode).'</th> 
                             <th>'.$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_amount', $this->languageCode).'</th>
                             <th>Status</th>
                          </tr>
                       </thead>
                       <tbody>';
                          foreach ($instalmentInfo['insDetails'] as $key => $instalment) {
                            $status = ($instalment['tid'] != '-') ? ($this->languageCode == 'ger' ? 'Bezahlt' : 'Paid') : ($this->languageCode == 'ger' ? 'Offen' : 'Open');
                          $webhookMessage .= '
                          <tr>
                             <td>'.$key.'</td>
                             <td>'.$instalment['future_instalment_date'].'</td>
                             <td>'.$instalment['tid'].'</td>
                             <td>'.$instalment['cycle_amount'].'</td>
                             <td>'.$status.'</td>
                          </tr>';
                          }
                          $webhookMessage .= '
                       </tbody>
                    </table>';

                    $data = new stdClass();
                    $data->nextInstalmentMsg = $instalmentMsg;
                    $data->instalmentMessage = $webhookMessage; 
                    $data->tkunde = $this->orderDetails->oKunde; 

                    // Constructing the mail object                     
                    $mail = new Mail();
                    $mail->setToMail($this->orderDetails->oKunde->cMail);
                    
                    // Replacing the template variable with the webhook message 
                    $mail = $mail->createFromTemplateID('kPlugin_' . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getID() . '_novalnetinstalmentmail', $data);
                    
                    // Preparing the shop send email function for dispatching the custom email 
                    $mailer = Shop::Container()->get(Mailer::class);
                    $mailer->send($mail);
            }
        }
    }
    
}
