<?php 
/**
 * This file is used for post processing
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: NovalnetWebhookHandler.php
 *
*/

namespace Plugin\jtl_novalnet\src;

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
    
    /**
     * Process the webhook notifications
     */
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
        $this->parentTid = (isset($this->eventData['event']['parent_tid']) && $this->eventData['event']['parent_tid'] != '') ? $this->eventData['event']['parent_tid'] : $this->eventData['event']['tid'];
        $this->eventTid  = $this->eventData['event']['tid'];
        
        // Retreiving the shop's order information based on the transaction 
        $this->orderDetails = $this->getOrderReference();
        
        $this->languageCode = ($this->orderDetails->kSprache == 1) ? 'ger' : 'eng';
        
        if ($this->eventData['result']['status'] == 'SUCCESS') {
            
            switch ($this->eventType) {
                case 'PAYMENT':
                    if ($this->parentTid != $this->orderDetails->nNntid) {
                        $this->handleNnZeroAmountBooking();
                        break;
                    }
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
                case 'PAYMENT_REMINDER_1':
                case 'PAYMENT_REMINDER_2':
                case 'SUBMISSION_TO_COLLECTION_AGENCY':
                    $this->handleNnPaymentNotifications();
                default:
                    $this->displayMessage('The webhook notification has been received for the unhandled EVENT type ( ' . $this->eventType . ')' );
            }
        } else {
            $this->displayMessage('Status is not valid...The webhook notification has been received for the unhandled EVENT type ( ' . $this->eventType . ')' );
        }
    }
    
    /**
     * Display webhook notification message 
     *
     * @param  string $message
     * @return none
     */
    public function displayMessage(string $message): void
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
        $novalnetHostIP = gethostbyname('pay-nn.de');
        $requestReceivedIP = $this->getRemoteAddress($novalnetHostIP);
        
        if (($novalnetHostIP) == '') {
            $this->displayMessage('Novalnet HOST IP missing');
        }
        
        // Condition to check whether the callback is called from authorized IP
        if (($novalnetHostIP !== $requestReceivedIP) && ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_webhook_testmode')) == '') {
            $this->displayMessage('Unauthorised access from the IP ' . $requestReceivedIP);
        }
    }

    /**
     * Retrieves the original remote ip address with and without proxy
     *
     * @return string
     */
	public function getRemoteAddress($novalnetHostIP)
	{
		$ip_keys = array('HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
		foreach ($ip_keys as $key) {
			if (array_key_exists($key, $_SERVER) === true) {
				if(in_array($key, ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST'])) {
					$forwardedIP = !empty($_SERVER[$key]) ? explode(',', $_SERVER[$key]) : [];
					if(in_array($novalnetHostIP, $forwardedIP)) {
						return $novalnetHostIP;
					} else {
						return $_SERVER[$key];
					}
				}
				return $_SERVER[$key];
			}
		}
	}
    
    /**
     * Validates the event parameters
     *
     * @return none
     */
    public function validateEventParams(): void
    {
		if(!empty( $this->eventData ['custom'] ['shop_invoked'])) {
			$this->displayMessage('Process already handled in the shop.');
		}
		
        // Mandatory webhook params
        $requiredParams = ['event' => ['type', 'checksum', 'tid'], 'result' => ['status']];
                                
        // Validate required parameters
        foreach ($requiredParams as $category => $parameters) {
            if (!isset($this->eventData[$category]) || ($this->eventData[$category]) == '') {
                // Could be a possible manipulation in the notification data
                $this->displayMessage('Required parameter category(' . $category. ') not received');
            } elseif (($parameters) != '') {
                foreach ($parameters as $parameter) {
                    if (($this->eventData[$category][$parameter]) == '') {
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
    public function validateChecksum(): void
    {
        $accessKey = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key'); 
        $tokenString  = $this->eventData['event']['tid'] . $this->eventData['event']['type'] . $this->eventData['result']['status'];
        
        if (isset($this->eventData['transaction']['amount'])) {
            $tokenString .= $this->eventData['transaction']['amount'];
        }
        
        if (isset($this->eventData['transaction']['currency'])) {
            $tokenString .= $this->eventData['transaction']['currency'];
        }
        
        if (($accessKey) != '') {
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
        $novalnetOrder = !empty($this->eventData['transaction']['order_no']) ? Shop::Container()->getDB()->queryPrepared('SELECT cNnorderid, cZahlungsmethode, cStatuswert, nBetrag, nNntid FROM xplugin_novalnet_transaction_details WHERE cNnorderid   = :cNnorderid', [':cNnorderid' => $this->eventData['transaction']['order_no']], ReturnType::SINGLE_OBJECT) : '';
        
        // If both the order number from Novalnet and in shop is missing, then something is wrong
        if (empty($this->eventData['transaction']['order_no']) && empty($novalnetOrder->cNnorderid)) {
			if($this->eventData['result']['status'] == 'SUCCESS') {
				$webhookMessage = $this->formCriticalMailBody($this->eventData);
				$this->sendCriticalMailNotification($webhookMessage);
			}
            $this->displayMessage('Order reference not found for the TID ' . $this->parentTid);
        }
        
        $orderNumberToSearch = !empty($novalnetOrder->cNnorderid) ? $novalnetOrder->cNnorderid : $this->eventData['transaction']['order_no'];
            
        // If the order in the Novalnet server to the order number in Novalnet database doesn't match, then there is an issue
        if (!empty($this->eventData['transaction']['order_no']) && !empty($novalnetOrder->cNnorderid) && (($this->eventData['transaction']['order_no']) != $novalnetOrder->cNnorderid)) {
            $this->displayMessage('Order reference not matching for the order number ' . $orderNumberToSearch);
        }        
                
        $shopOrder = Shop::Container()->getDB()->queryPrepared('SELECT kBestellung FROM tbestellung WHERE cBestellNr  = :cBestellNr', [':cBestellNr' => $orderNumberToSearch,], ReturnType::SINGLE_OBJECT);
            
        // Loads order object from shop 
        if (!empty($shopOrder->kBestellung)) {
			$order = new Bestellung((int) $shopOrder->kBestellung); 
			$order->fuelleBestellung(true, 0, false);
		} else {
			$this->displayMessage('Transaction mapping failed ' . $orderNumberToSearch);
		}
        
        // Assign if payment module id is not exist in the order object
        if(empty($order->Zahlungsart)) {
			$paymentMethodDetail = Shop::Container()->getDB()->queryPrepared('SELECT cModulId FROM tzahlungsart WHERE cModulId LIKE :novalnet', [':novalnet' => '%novalnet%'], ReturnType::SINGLE_OBJECT);
			$order->Zahlungsart =  new stdClass();
			$order->Zahlungsart->cModulId = $paymentMethodDetail->cModulId;
		}
		
		// Check it may be zero amount process
        $txAdditonalInfo = Shop::Container()->getDB()->queryPrepared('SELECT nNntid, cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $orderNumberToSearch], ReturnType::SINGLE_OBJECT);
        $isZeroAmountBooking = !empty($txAdditonalInfo->cAdditionalInfo) ? json_decode($txAdditonalInfo->cAdditionalInfo, true) : [];
        if(!empty($isZeroAmountBooking['zero_amount'])) {
			$novalnetOrder =  new stdClass();
			$novalnetOrder->nNntid = $txAdditonalInfo->nNntid;
		}
        
        // If the order is not found in Novalnet's database, it is communication failure
        if (empty($novalnetOrder->cNnorderid) && empty($isZeroAmountBooking['zero_amount'])) {
          
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
     * @param  object $order
     * @return none
     */
    public function handleCommunicationBreak(object $order): void
    {      
        $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
        
        $orderLanguage = ($order->kSprache == 1) ? 'ger' : 'eng';
        
        $webhookComments  = '';
        
        $webhookComments .= $order->cZahlungsartName;
        
        $webhookComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_transaction_tid', $orderLanguage) . ': '. $this->parentTid;
            
        if (!empty($this->eventData['transaction']['test_mode'])) {
            $webhookComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_test_order', $orderLanguage);
        }
        
        $isPaidNow = false;
        
        // Deactivating the WAWI synchronization 
        $updateWawi = 'Y';
        
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

                    $orderStatus = \BESTELLUNG_STATUS_BEZAHLT;
                    
                    // Handle the zero amount process
                    if ($this->eventData['transaction']['amount'] == 0) {
						$orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status'));
						$isPaidNow = false;
						$webhookComments .= \PHP_EOL . $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_zero_amount_booking_text', $orderLanguage);
					}
                    if ($this->eventData['transaction']['amount'] != 0) {
						// Add the current transaction payment into db
						$incomingPayment           = new stdClass();
						$incomingPayment->fBetrag  = $order->fGesamtsummeKundenwaehrung;
						$incomingPayment->cISO     = $order->Waehrung->cISO;
						$incomingPayment->cHinweis = $this->parentTid;
						$jtlPaymentmethod->name    = $order->cZahlungsartName;
					
						$jtlPaymentmethod->addIncomingPayment($order, $incomingPayment); 
					}                           
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
        $this->novalnetPaymentGateway->insertOrderDetailsIntoNnDb($order, $this->eventData, strtolower($this->eventData['transaction']['payment_type']));               
        
        // Completing the callback notification 
        $this->webhookFinalprocess($webhookComments, $orderStatus, $updateWawi, $isPaidNow);
    }
    
    /**
     * Handling the Novalnet transaction Zero amount booking process
     *
     * @return none
     */
    public function handleNnZeroAmountBooking(): void
    {
        $txAdditonalDetails = Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $this->orderDetails->cBestellNr], ReturnType::SINGLE_OBJECT);
        $txAdditonalInfo = !empty($txAdditonalDetails->cAdditionalInfo) ? json_decode($txAdditonalDetails->cAdditionalInfo, true) : [];
        if(!empty($txAdditonalInfo['zero_amount'])) {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['nNntid' => $this->parentTid, 'nBetrag' => $this->eventData['transaction']['amount'], 'cAdditionalInfo' => json_encode(array('tid' => $this->orderDetails->nNntid)), 'nCallbackAmount' =>  $this->eventData['transaction']['amount']]);
        }
        $zeroAmountBooking = $this->novalnetPaymentGateway->convertCurrencyFormatter($this->orderDetails->kBestellung, ($this->eventData['transaction']['amount']));
        
        $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_zero_amount_booking', $this->languageCode), $zeroAmountBooking, $this->parentTid);
        
        $this->addPaymentsToOrder($this->orderDetails, $this->orderDetails->nNntid);
        $this->webhookFinalprocess($webhookComments, \BESTELLUNG_STATUS_BEZAHLT, 'N', true);
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
                
                // Instalment payment methods cycle information  
                if (!empty($this->eventData['instalment'])) {
					$txAdditonalDetails = $this->novalnetPaymentGateway->novalnetPaymentHelper->getAdditionalInfo($this->orderDetails->cBestellNr);
					$txAdditonalInfo = !empty($txAdditonalDetails->cAdditionalInfo) ? json_decode($txAdditonalDetails->cAdditionalInfo, true) : [];
                    $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode(array_merge((array) $this->eventData['instalment'], (array) $txAdditonalInfo))]);
                }   
                                                                                    
                                                
                // Only for the Invoice Payment type, we have to wait for the credit entry 
                if ($this->eventData['transaction']['payment_type'] != 'INVOICE') {
                    // Add payment to order
                    $this->addPaymentsToOrder($this->orderDetails, $this->parentTid); 
                    
                    $isPaidNow = true;                    
                }
               
                // Order status required to be changed for the payment type 
                $orderStatus = ($this->eventData['transaction']['status'] == 'PENDING') ?  \BESTELLUNG_STATUS_OFFEN : \BESTELLUNG_STATUS_BEZAHLT;
                
                                                                 
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
        
        // orderStatus is set to empty
        $orderStatus = '';
        
        // webhookComments is set to empty
        $webhookComments = '';
        
        // Set the WAWI status
        $updateWawi = ($this->eventData['transaction']['status'] == 'CONFIRMED') ? 'N' : 'Y';
        
        // Set the transaction amount
        $amount = (strpos($this->eventData['transaction']['payment_type'], 'INSTALMENT') !== false) ? $this->eventData['instalment']['cycle_amount'] : $this->eventData['transaction']['amount'];
        
        if ($this->eventData['transaction']['update_type'] == 'STATUS') {
            
            if ($this->eventData['transaction']['status'] == 'DEACTIVATED') {
                        
                $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_transaction_cancellation', $this->languageCode), date('d.m.Y'), date('H:i:s'));
                
                // Cancellation of the order status 
                $orderStatus = \BESTELLUNG_STATUS_STORNO;
            
            } else {
                
                if ($this->orderDetails->cStatuswert == 'PENDING' && in_array($this->eventData['transaction']['status'], ['ON_HOLD', 'CONFIRMED'])) {
                
                    $webhookComments = '';
                
                    if (in_array($this->eventData['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
                        $webhookComments = $this->novalnetPaymentGateway->getBankdetailsInformation($this->orderDetails, $this->eventData, $this->languageCode);
                    }
                    
                    // For on-hold, we only update the order status 
                    if ($this->eventData['transaction']['status'] == 'ON_HOLD') {
                        // Building the onhold activation text 
                        $webhookComments .= \PHP_EOL . \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_pending_to_onhold_status_change', $this->languageCode), $this->parentTid, date('d.m.Y'), date('H:i:s'));
                        
                        $orderStatus = constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status'));
                    } else {
                        // Building the confirmation text 
                        $webhookComments .= \PHP_EOL . \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_update_confirmation_text', $this->languageCode), $this->parentTid, $this->eventData['transaction']['amount'] / 100, $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'));
                        
                        // Instalment payment methods cycle information  
                        if (!empty($this->eventData['instalment'])) { 
							$txAdditonalDetails = $this->novalnetPaymentGateway->novalnetPaymentHelper->getAdditionalInfo($this->orderDetails->cBestellNr);
							$txAdditonalInfo = !empty($txAdditonalDetails->cAdditionalInfo) ? json_decode($txAdditonalDetails->cAdditionalInfo, true) : [];
                            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode(array_merge((array) $this->eventData['instalment'], (array) $txAdditonalInfo))]);
                        }
                        
                        $orderStatus = \BESTELLUNG_STATUS_BEZAHLT;
                        // Add payment to order
                        $this->addPaymentsToOrder($this->orderDetails, $this->parentTid);
                        // Paid is set to NULL
                        $isPaidNow = true; 
                                         
                    }
                }

            }
        } else {

            $updateAmount = $this->novalnetPaymentGateway->convertCurrencyFormatter($this->orderDetails->kBestellung, ($this->eventData['transaction']['amount'] ));
            
            $dueDateUpdateMessage = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_duedate_update_message', $this->languageCode), $updateAmount, $this->eventData['transaction']['due_date']);

            // Amount update process
            if($amount != $this->orderDetails->nBetrag && !in_array($this->eventData['transaction']['payment_type'], array('INSTALMENT_INVOICE', 'INSTALMENT_SEPA'))) {
                    $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['nBetrag' => $amount]);
            }
            
            $amountUpdateMessage = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_amount_update_message', $this->languageCode), $updateAmount, date('d.m.Y'), date('H:i:s'));
            
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
        if ($this->eventData['result']['status'] == 'SUCCESS') { 

			$refundedAmount = $this->novalnetPaymentGateway->convertCurrencyFormatter($this->orderDetails->kBestellung, ($this->eventData['transaction']['refund']['amount'] ));

			$webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_refund_execution', $this->languageCode), $this->parentTid, $refundedAmount); 

            if (!empty($this->eventData['transaction']['refund']['tid'])) {
                $webhookComments .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_new_tid_refund_execution', $this->languageCode), $this->eventData['transaction']['refund']['tid']);
            } 
            
            // In case of full refund, deactivation is processed 
            if ($this->eventData['transaction']['status'] == 'DEACTIVATED') {
                
                // We do not send to webhookFinalprocess for update as it will cause problems with WAWI synchronization
                $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $this->orderDetails->cBestellNr, ['cStatus' => \BESTELLUNG_STATUS_STORNO]);                               
            }
            
            $txAdditonalDetails = Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $this->orderDetails->cBestellNr], ReturnType::SINGLE_OBJECT);

            $txAdditonalInfo = !empty($txAdditonalDetails->cAdditionalInfo) ? json_decode($txAdditonalDetails->cAdditionalInfo, true) : [];
            
            $txAdditonalInfo['refunded_amount'] = $this->eventData['transaction']['refunded_amount'] + $this->eventData['transaction']['refund']['amount'];
            
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode($txAdditonalInfo)]);
            
        }  else {
			$webhookComments = PHP_EOL . $response['result']['status_text'];
		}  
        
         $this->webhookFinalprocess($webhookComments);
    }
    
    /**
     * Handling the credit process
     *
     * @return none
     */
    public function handleNnTransactionCredit(): void
    {
		$creditAmount = $this->novalnetPaymentGateway->convertCurrencyFormatter($this->orderDetails->kBestellung, ($this->eventData['transaction']['amount'] ));
		
        $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_webhook_initial_execution', $this->languageCode), $this->parentTid, $creditAmount, date('d.m.Y'), date('H:i:s'), $this->eventTid);
        
        if (in_array($this->eventData['transaction']['payment_type'], ['INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'ONLINE_TRANSFER_CREDIT', 'MULTIBANCO_CREDIT'])) {
            
            $callbackDetails = Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo, nCallbackAmount FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $this->orderDetails->cBestellNr], ReturnType::SINGLE_OBJECT);

            $callbackDetails->nCallbackAmount = !empty($callbackDetails->nCallbackAmount) ? $callbackDetails->nCallbackAmount : 0;
            
            $orderPaidAmount = $callbackDetails->nCallbackAmount + $this->eventData['transaction']['amount'];            
            $orderAmount = !empty($this->orderDetails->nBetrag) ? $this->orderDetails->nBetrag : ($this->orderDetails->fGesamtsumme * 100);
            
            $refundInfo = !empty($callbackDetails->cAdditionalInfo) ? json_decode($callbackDetails->cAdditionalInfo, true) : 0;
            
            $orderPaidAmount = $orderPaidAmount - (!empty($refundInfo['refunded_amount']) ? $refundInfo['refunded_amount'] : 0);
            $orderAmount = $orderAmount - (!empty($refundInfo['refunded_amount']) ? $refundInfo['refunded_amount'] : 0);
            $this->eventData['order_paid_amount'] = $orderPaidAmount;
            if ($orderPaidAmount >= $orderAmount) {
                $amount = ($orderPaidAmount == $orderAmount) ? ($orderAmount / 100) : ($this->eventData['transaction']['amount'] / 100);
                // Add payment to order
                $this->addPaymentsToOrder($this->orderDetails, $this->parentTid, $amount);
                
                // Update the order status
                if ($this->eventData['transaction']['payment_type'] == 'ONLINE_TRANSFER_CREDIT') {                  
                    $this->webhookFinalprocess($webhookComments, \BESTELLUNG_STATUS_BEZAHLT, 'N', true);
                }
                $this->webhookFinalprocess($webhookComments, \BESTELLUNG_STATUS_BEZAHLT);   
            }                                                   
        }
        $this->webhookFinalprocess($webhookComments);
    }
    
    /**
     * Handling the chargeback process
     *
     * @return none
     */
    public function handleNnChargeback(): void
    {
		$chargeBackAmount = $this->novalnetPaymentGateway->convertCurrencyFormatter($this->orderDetails->kBestellung, ($this->eventData['transaction']['amount'] ));
		
        if ($this->eventData['transaction']['payment_type'] == 'RETURN_DEBIT_SEPA') {
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_return_debit_execution_text', $this->languageCode), $this->parentTid, $chargeBackAmount, date('d.m.Y'), date('H:i:s'), $this->eventTid);

        } elseif ($this->eventData['transaction']['payment_type'] == 'REVERSAL') {
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_reversal_execution_text', $this->languageCode), $this->parentTid, $chargeBackAmount, date('d.m.Y'), date('H:i:s'), $this->eventTid);
        } else {
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_chargeback_execution_text', $this->languageCode), $this->parentTid, $chargeBackAmount, date('d.m.Y'), date('H:i:s'), $this->eventTid);
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
            $additionalInstalmentMsg = '';
            
            $installmentAmount = $this->novalnetPaymentGateway->convertCurrencyFormatter($this->orderDetails->kBestellung, ($this->eventData['instalment']['cycle_amount']));
            
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_payment_execution', $this->languageCode), $this->parentTid, $this->eventTid, $installmentAmount, date('d.m.Y'), date('H:i:s'));
            
            $additionalInfo = Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $this->orderDetails->cBestellNr,], ReturnType::SINGLE_OBJECT);
           
            $instalmentCyclesInfo = json_decode($additionalInfo->cAdditionalInfo, true);
            $insCycleCount = $this->eventData['instalment']['cycles_executed'];
            $instalmentCyclesInfo[$insCycleCount]['tid'] = $this->eventTid;
            
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode($instalmentCyclesInfo)]);
            
            if (empty($this->eventData['instalment']['prepaid'])) {
                if ($this->eventData['transaction']['payment_type'] == 'INSTALMENT_INVOICE') {
                    $additionalInstalmentMsg = \PHP_EOL . $this->novalnetPaymentGateway->getBankdetailsInformation($this->orderDetails, $this->eventData, $this->languageCode);
                    
                }
            }
            
            $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($this->orderDetails->cBestellNr, $this->languageCode, $this->orderDetails->kBestellung);
            
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
        if ($this->eventData['transaction']['status'] == 'CONFIRMED' && $this->orderDetails->cStatuswert != 'DEACTIVATED') {
            $additionalInfo = Shop::Container()->getDB()->queryPrepared('SELECT cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $this->orderDetails->cBestellNr,], ReturnType::SINGLE_OBJECT);
           
            $instalmentCyclesInfo = json_decode($additionalInfo->cAdditionalInfo, true);
            
            $instalmentCyclesInfo['is_full_instalment_cancel'] = ($this->eventData['instalment']['cancel_type'] == 'ALL_CYCLES') ? 'all_cycles' : 'remaining_cycles';
            
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cAdditionalInfo' => json_encode($instalmentCyclesInfo)]);
            
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_instalment_cancellation', $this->languageCode), $this->parentTid, date('d.m.Y'), date('H:i:s'));             
            
            if ($instalmentCyclesInfo['is_full_instalment_cancel'] == 'all_cycles') {
                $this->webhookFinalprocess($webhookComments, \BESTELLUNG_STATUS_STORNO, 'Y');
                $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $this->orderDetails->cBestellNr, ['cStatuswert' => 'DEACTIVATED']);
            } else {
                $this->webhookFinalprocess($webhookComments);
            }
        }
    }
    
    /**
     * Handling the Payment notification process
     *
     * @return none
     */
    public function handleNnPaymentNotifications(): void
    {
        if(in_array($this->eventType, ['PAYMENT_REMINDER_1', 'PAYMENT_REMINDER_2'])) {
            $reminderNumber = preg_replace("/[^0-9]/", '', $this->eventType);
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_payment_reminder', $this->languageCode), $reminderNumber);  
        } else {
            $webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_collection_submission', $this->languageCode), $this->eventData['collection']['reference']);  
        }
        $this->webhookFinalprocess($webhookComments);
    }
    
    /**
     * Create the payment to the Novalnet orders
     *
     * @param  object  $orderDetails
     * @param  int  $tid
     * @param  float|int  $amount
     * @return none
     */
    public function addPaymentsToOrder(object $orderDetails, int $tid, $amount = 0): void
    {
        // Loads the order object
        $order = new Bestellung((int) ($orderDetails->kBestellung)); 
        $order->fuelleBestellung(true, 0, false);

        // Add the incoming payments if the transaction was confirmed
        $jtlPaymentmethod = Method::create($orderDetails->Zahlungsart->cModulId);
        
        $incomingPayment           = new stdClass();
        $incomingPayment->fBetrag  = !empty($amount) ?  $amount : $orderDetails->fGesamtsummeKundenwaehrung;
        $incomingPayment->cISO     = $orderDetails->Waehrung->cISO;
        $incomingPayment->cHinweis = $tid;
        $jtlPaymentmethod->name    = $orderDetails->cZahlungsartName;
        
        // Add the current transaction payment into db
        $jtlPaymentmethod->addIncomingPayment($order, $incomingPayment);
    }
    
    /**
     * Performs final callback process
     *
     * @param  string  $webhookMessage
     * @param  string  $orderStatusToUpdate
     * @param  string  $updateWawiStatus
     * @param  bool    $isPaidNow
     * @return none
     */
    public function webhookFinalprocess(string $webhookMessage, string $orderStatusToUpdate = '', string $updateWawiStatus = 'N', bool $isPaidNow = false): void
    {  
        $oldTransactionComment = !empty($this->orderDetails->cBestellNr) ? Shop::Container()->getDB()->queryPrepared('SELECT cKommentar, kSprache FROM tbestellung WHERE cBestellNr  = :cBestellNr', [':cBestellNr' => $this->orderDetails->cBestellNr,], ReturnType::SINGLE_OBJECT) : '';
        
        $txAdditonalDetails = !empty($this->orderDetails->cBestellNr) ? $this->novalnetPaymentGateway->novalnetPaymentHelper->getAdditionalInfo($this->orderDetails->cBestellNr) : [];
		$txAdditonalInfo = !empty($txAdditonalDetails->cAdditionalInfo) ? json_decode($txAdditonalDetails->cAdditionalInfo, true) : [];

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
            
           if(($this->eventType == 'INSTALMENT' || $this->eventType == 'TRANSACTION_UPDATE' && in_array($this->eventData['transaction']['update_type'], array('AMOUNT', 'AMOUNT_DUE_DATE')))  && (preg_match('/(.*)to the following/', $oldTransactionComment->cKommentar, $matches) || preg_match('/(.*)spätestens/', $oldTransactionComment->cKommentar, $matches))) {
                $updatedOrderAmount = $this->eventType == 'INSTALMENT' ? $this->eventData['instalment']['cycle_amount'] : 
                $this->eventData['transaction']['amount'];

                $oldTransactionComment->cKommentar = ($oldTransactionComment->kSprache == 1) ? str_replace($matches[1], 'Bitte überweisen Sie den Betrag von ' . number_format($updatedOrderAmount / 100 , 2, ',', '') . ' '. $this->orderDetails->Waehrung->htmlEntity, $oldTransactionComment->cKommentar) :  str_replace($matches[1], 'Please transfer the amount of ' . number_format($updatedOrderAmount / 100 , 2, ',', '') . ' '. $this->orderDetails->Waehrung->htmlEntity , $oldTransactionComment->cKommentar);
            } 
        }
        
        if ($this->eventType == 'INSTALMENT' && preg_match("/([0-9]{17})/s", $oldTransactionComment->cKommentar, $matches)) {
                 if (strpos($oldTransactionComment->cKommentar, 'Novalnet-Transaktions-ID:') !== false && $oldTransactionComment->kSprache == 1) {
                    $oldTransactionComment->cKommentar = str_replace('Novalnet-Transaktions-ID: ' . $matches[0], 'Novalnet-Transaktions-ID: ' . $this->eventTid, $oldTransactionComment->cKommentar);
                    $oldTransactionComment->cKommentar = str_replace('Zahlungsreferenz 1: ' . $matches[0], 'Zahlungsreferenz 1: ' . $this->eventTid, $oldTransactionComment->cKommentar);
                    $oldTransactionComment->cKommentar = str_replace('Zahlungsreferenz: ' . $matches[0], 'Zahlungsreferenz: ' . $this->eventTid, $oldTransactionComment->cKommentar);
                } else {
                  $oldTransactionComment->cKommentar = str_replace('Novalnet Transaction ID: ' . $matches[0], 'Novalnet transaction ID: ' . $this->eventTid, $oldTransactionComment->cKommentar);   
                  $oldTransactionComment->cKommentar = str_replace('Payment Reference 1: ' . $matches[0], 'Payment Reference 1: ' . $this->eventTid, $oldTransactionComment->cKommentar);
                  $oldTransactionComment->cKommentar = str_replace('Payment Reference: ' . $matches[0], 'Payment Reference: ' . $this->eventTid, $oldTransactionComment->cKommentar);
                }
            }

        $txComment = isset($txAdditonalInfo['cKommentar']) ? $txAdditonalInfo['cKommentar'] : '';
		$webhookComments = !empty($oldTransactionComment->cKommentar) ? ($oldTransactionComment->cKommentar . \PHP_EOL . $webhookMessage) : ($txComment . \PHP_EOL . $webhookMessage);

        $orderNo = !empty($this->orderDetails->cBestellNr) ? ($this->orderDetails->cBestellNr) : $this->eventData['transaction']['order_no'];

        if (!empty($orderStatusToUpdate)) {
            // Updating the necessary details into the core order table related to the webhook transaction
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $orderNo, ['cKommentar' => $webhookComments, 'cStatus' => $orderStatusToUpdate, 'cAbgeholt' => $updateWawiStatus, 'dBezahltDatum' => ($isPaidNow ? 'NOW()' : '')]);
        } else {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $orderNo, ['cKommentar' => $webhookComments, 'cAbgeholt' => $updateWawiStatus, 'dBezahltDatum' => ($isPaidNow ? 'NOW()' : '')]);
        }
        
        // Set the order paid amount in the Novalnet transaction table
        if (isset($this->eventData['order_paid_amount']) && !empty($this->eventData['order_paid_amount'])) {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $orderNo, ['nCallbackAmount' => $this->eventData['order_paid_amount']]);   
        }

		$txAdditonalInfo['cKommentar'] = !empty($txAdditonalInfo['cKommentar']) ? $txAdditonalInfo['cKommentar'] .\PHP_EOL. $webhookMessage : $webhookMessage;

        // Updating in the Novalnet transaction table
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $orderNo, ['cStatuswert' => $this->eventData['transaction']['status'], 'cAdditionalInfo' =>  json_encode($txAdditonalInfo)]);   

        // Send the order update mail when the transaction has been confirmed or amount and due date update
        if ($this->eventType == 'TRANSACTION_CAPTURE' || (isset($this->eventData['transaction']['update_type']) && ((in_array($this->eventData['transaction']['update_type'], array('AMOUNT', 'DUE_DATE', 'AMOUNT_DUE_DATE'))) || ($this->eventData['transaction']['update_type'] == 'STATUS' && $this->eventData['transaction']['status'] == 'CONFIRMED')))) {
            $jtlPaymentmethod = Method::create($this->orderDetails->Zahlungsart->cModulId);

            $jtlPaymentmethod->sendMail($this->orderDetails->kBestellung, MAILTEMPLATE_BESTELLUNG_AKTUALISIERT);
        
        }
        
        // Send mail for merchant
        $this->sendMailNotification($webhookMessage);
    }
    
    /**
     * Triggers mail notification to the mail address specified
     *
     * @param  array $webhookMessage
     * @return none
     */
    public function sendMailNotification(string $webhookMessage): void
    {
        // Looping in through the core plugin's mail templates
        foreach ($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getMailTemplates()->getTemplatesAssoc() as $mailTemplate) {
            
            if ($mailTemplate->cModulId == 'novalnetwebhookmail' && $mailTemplate->cAktiv == 'Y') {
                
                $adminDetails = Shop::Container()->getDB()->queryPrepared('SELECT cMail from tadminlogin LIMIT 1', [], ReturnType::SINGLE_OBJECT);
                
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
    
    
    /**
     * Triggers mail notification to the further instalment
     *
     * @param  array  $instalmentInfo
     * @param  string $additionalInfo
     * @return none
     */
    public function sendInstalmentMailNotification(array $instalmentInfo, string $additionalInfo): void
    {
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
                            $futureInstalmentDate = $instalment['future_instalment_date'] ?? '-';
                          $webhookMessage .= '
                          <tr>
                             <td>'.$key.'</td>
                             <td>'.$futureInstalmentDate.'</td>
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
    
    /**
     * Form the critical mail notification messages
     *
     * @param array $data
     * @return string
     */
    public function formCriticalMailBody($data, $lang = null)	{
		
        $webhookMessage  = $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_title', $lang);
        
        $webhookMessage .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_project_id', $lang), $data['merchant']['project']);
        $webhookMessage .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_tid', $lang), $data['transaction']['tid']);
        $webhookMessage .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_tid_status', $lang), $data['transaction']['status']);
        $webhookMessage .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_payment_type', $lang), $data['transaction']['payment_type']);
        $webhookMessage .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_amount', $lang), ($data['transaction']['amount'] / 100) . ' ' . $data['transaction']['currency']);
        $webhookMessage .= \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_customer_email', $lang), $data['customer']['email']);              
        $webhookMessage  .= PHP_EOL . PHP_EOL .$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_webhook_communication', $lang);
        $webhookMessage  .= PHP_EOL . PHP_EOL .$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_discrepancies', $lang);
        $webhookMessage  .= PHP_EOL . PHP_EOL .$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_manual_order_creation', $lang);
        $webhookMessage  .= PHP_EOL . PHP_EOL .$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_refund_initiation', $lang);
        $webhookMessage  .= PHP_EOL . PHP_EOL .$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_critical_mail_promt_review', $lang);
        return $webhookMessage;

    }
    
    /**
     * Triggers critical mail notification to the mail address specified
     *
     * @param array $webhookMessage
     * @return none
     */
    public function sendCriticalMailNotification($webhookMessage)
    {
        // Looping in through the core plugin's mail templates
        foreach ($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getMailTemplates()->getTemplatesAssoc() as $mailTemplate) {
            
            if ($mailTemplate->cModulId == 'novalnetcriticalwebhookmail' && $mailTemplate->cAktiv == 'Y') {
                
                $adminDetails = Shop::Container()->getDB()->queryPrepared('SELECT cLogin, cMail from tadminlogin LIMIT 1', [], ReturnType::SINGLE_OBJECT);
                
                // Notification is sent only when the admin login email is configured 
                if (!empty($adminDetails->cMail)) {                 
                
                    $data = new stdClass();
                    $data->webhookMessage = nl2br($webhookMessage); 
                    $data->name = nl2br($adminDetails->cLogin); 
                         

                    // Constructing the mail object                     
                    $mail = new Mail();
                    $mail->setToMail($adminDetails->cMail);
                   
                    // Replacing the template variable with the webhook message 
                    $mail = $mail->createFromTemplateID('kPlugin_' . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getID() . '_novalnetcriticalwebhookmail', $data);

                    // Preparing the shop send email function for dispatching the custom email 
                    $mailer = Shop::Container()->get(Mailer::class);
                    $mailer->send($mail);
                }
            }
        }

    }
    
    /**
     * Triggers refund process through the wawi workflow
     *
     * @return none
     */
    public function handleNovalnetRefund() {
		// Get the order number from wawi
		$getKeyValues = $_POST;
		$orderNumber  = array_keys($getKeyValues);
		// Get the amount, and TID from Database
		$orderDetails = Shop::Container()->getDB()->queryPrepared('SELECT nBetrag, nNntid, cAdditionalInfo FROM xplugin_novalnet_transaction_details WHERE cNnorderid  = :cNnorderid', [':cNnorderid' => $orderNumber[0]], ReturnType::SINGLE_OBJECT);
		
		$oldTransactionComment = Shop::Container()->getDB()->queryPrepared('SELECT kBestellung, cKommentar, kSprache FROM tbestellung WHERE cBestellNr = :cBestellNr', [':cBestellNr' => $orderNumber[0] ], ReturnType::SINGLE_OBJECT);
		
		$amount 				= json_decode($orderDetails->nBetrag, true);
		$tid    				= json_decode($orderDetails->nNntid, true);
		$txAdditonalDetails    	= json_decode($orderDetails->cAdditionalInfo, true);
		$kBestellung    		= json_decode($oldTransactionComment->kBestellung, true);
		$lang    				= json_decode($oldTransactionComment->kSprache, true);
		$this->languageCode 	= $lang == 2 ? 'eng' : 'ger';
		// Form the refund request Parameters
		$data = [];
		$data['transaction'] = 	[
									'tid'    => $tid,
									'amount' => $amount,
								];
		$data['custom'] = 	[
								'lang'      	 => $lang == 2 ? 'EN' : 'DE',  
								'shop_invoked' 	 => 1
							];
		// Do the refund call to Novalnet server
		$response = $this->novalnetPaymentGateway->performServerCall($data, 'transaction_refund');

		// Handling the refund success response
		if ($response['result']['status'] == 'SUCCESS') {

			// Form the refund new tid comments by the response
			$refundedAmount = $this->novalnetPaymentGateway->convertCurrencyFormatter($kBestellung, ($response['transaction']['refund']['amount']));

			$webhookComments = \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_refund_execution', $this->languageCode), $response['transaction']['tid'], $refundedAmount); 

			if (!empty($response['transaction']['refund']['tid'])) {
				$webhookComments .= PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLangTranslationText('jtl_novalnet_new_tid_refund_execution', $this->languageCode), $response['transaction']['refund']['tid']);
			} 
			// Store the refunded amount details into the Database
			$txAdditonalInfo = !empty($txAdditonalDetails) ? $txAdditonalDetails : [];
			$txAdditonalInfo['refunded_amount'] = $response['transaction']['refunded_amount'];
			$this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'cNnorderid', $orderNumber[0], ['cAdditionalInfo' => json_encode($txAdditonalInfo)]);
		} else {
			$webhookComments = PHP_EOL . $response['result']['status_text'];
		}
		$txAdditonalDetails = $this->novalnetPaymentGateway->novalnetPaymentHelper->getAdditionalInfo($orderNumber[0]);  
		$txAdditonalInfo = !empty($txAdditonalDetails->cAdditionalInfo) ? json_decode($txAdditonalDetails->cAdditionalInfo, true) : [];
		// Store the refund comments into the Database
		$webhookMessage = !empty($oldTransactionComment->cKommentar) ? ($oldTransactionComment->cKommentar . \PHP_EOL . $webhookComments) : $txAdditonalInfo['cKommentar'] .\PHP_EOL .$webhookComments;
		$cKommentar = array('cKommentar' => $webhookMessage);
		$cStatus    = [];
		// In case of full refund, deactivation is processed
		if ($response['transaction']['status'] == 'DEACTIVATED') {
			// We do not send to webhookFinalprocess for update as it will cause problems with WAWI synchronization
			$cStatus = array('cStatus' => \BESTELLUNG_STATUS_STORNO);
		}
		$updateValues = array_merge($cKommentar, $cStatus);
		// Update comments and status into the Database
		$this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $orderNumber[0], $updateValues);
		
	}
	
	/**
     * Handling the invoice number update process from wawi
     *
     * @return none
     */
	public function handleNovalnetInvoice() {
		
		// Get the order number and Invoice number from wawi
		$postData = $_POST;
		
		foreach($postData as $keys => $values ) {
			$postData = explode(',',$values);
			// Get the tid from database
			$orderDetails = Shop::Container()->getDB()->queryPrepared('SELECT nNntid FROM xplugin_novalnet_transaction_details WHERE cNnorderid = :cNnorderid', [':cNnorderid' => $postData[0]], ReturnType::SINGLE_OBJECT);
			// Get the language from database
			$lang = Shop::Container()->getDB()->queryPrepared('SELECT kSprache FROM tbestellung WHERE cBestellNr = :cBestellNr', [':cBestellNr' => $postData[0]], ReturnType::SINGLE_OBJECT);
			$tid    				= json_decode($orderDetails->nNntid, true);
			$lang    				= json_decode($lang->kSprache, true);

			// Form the invoice number update request Parameters
			$data = [];
			$data['transaction'] = 	[
										'tid'    	  => $tid,
										'invoice_no'  => $postData[1],
									];
			$data['custom'] = 	[
									'lang'      	 => $lang == 2 ? 'EN' : 'DE',  
								];
			// Do the transaction update call to Novalnet server
			$response = $this->novalnetPaymentGateway->performServerCall($data, 'transaction_update');
		}
	}
}

