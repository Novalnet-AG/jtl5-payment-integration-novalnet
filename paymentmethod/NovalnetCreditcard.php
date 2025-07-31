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
 * Script: NovalnetCreditcard.php
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
use JTL\Helpers\Text;
use JTL\Checkout\OrderHandler;

/**
 * Class NovalnetCreditcard
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetCreditcard extends Method
{     
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;
    
 
    /**
     * @var string
     */
    private $paymentName = 'novalnet_cc';
    
    /**
     * NovalnetCreditcard constructor.
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

        $this->name    = 'Novalnet Kredit-/Debitkarte';
        $this->caption = 'Novalnet Kredit-/Debitkarte';

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
     * Called when additional template is used
     *
     * @param  object $post
     * @return bool
     */
    public function handleAdditional(array $post): bool
    {
        $this->novalnetPaymentGateway->novalnetPaymentHelper->novalnetSessionCleanUp($this->paymentName);
        
        // If the additional template has been processed, we set the post data in the payment session 
        if (isset($post['nn_payment'])) { 
            $_SESSION[$this->paymentName] = array_map('trim', $post);
            return true;
        }
            
        // If the customer has placed a succesful order already and have opted for quick checkout, then previous card details are restored
        $cardDetails = '';
        if ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('one_click_shopping', $this->paymentName) && (Frontend::getCustomer()->kKunde) != '') {
            $proceedOneclickShopping = 1;
            $cardDetails = $this->novalnetPaymentGateway->novalnetPaymentHelper->getPaymentReferenceValues($this->paymentName);
        }           
        
        // Handle additional data is called only when the Customer does not have a company field                        
        Shop::Smarty()->assign('pluginPath', $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getPaths()->getBaseURL())
                      ->assign('ccFormDetails', $this->getCreditCardAuthenticationCallData())
                      ->assign('creditcardFields', $this->getDynamicCreditCardFormFields())
                      ->assign('cardDetails', $cardDetails)                       
                      ->assign('oneClickShoppingEnabled', $proceedOneclickShopping);                                                        
        return false;
    }

    /**
     * Called when the additional template is submitted
     * 
     * @return bool
     */
    public function validateAdditional(): bool
    {
        return false;
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
        $paymentRequestData['transaction']['payment_type'] = 'CREDITCARD';
        
        // Checking if the payment type has authorization is in place or immediate capture 
        $paymentRequestData['payment_url'] = !empty($this->novalnetPaymentGateway->isTransactionRequiresAuthorizationOnly($paymentRequestData['transaction']['amount'], $this->paymentName)) ? 'authorize' : 'payment';
        $_SESSION['nn_'. $this->paymentName . '_url'] = $paymentRequestData['payment_url'];
        // If the consumer has opted to pay with the saved account data, we use the token relavant to that      
        if (!empty($_SESSION[$this->paymentName]['nn_customer_selected_token'])) {
            // Selected token is the key to the stored payment data             
            $paymentRequestData['transaction']['payment_data']['token'] = $_SESSION[$this->paymentName]['nn_customer_selected_token'];      
        } else {        
            // If the consumer has opted to save the account data for future purchases, we notify the server
            if (!empty($_SESSION[$this->paymentName]['nn_save_payment_data'])) {
                $paymentRequestData['transaction']['create_token'] = 1;
            }
                                    
            // Setting up the alternative card data to the server for card processing
            $paymentRequestData['transaction']['payment_data'] = [
                                                                    'pan_hash'   => $_SESSION[$this->paymentName]['nn_cc_panhash'],
                                                                    'unique_id'  => $_SESSION[$this->paymentName]['nn_cc_uniqueid']
                                                                 ];
                                                                
            // If the enforced 3D option is enabled, we notify the server about the forced 3D handling                                                   
            if ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('enforce_option', $this->paymentName)) {
                $paymentRequestData['transaction']['payment_data']['enforce_3d'] = 1;
            }
            
            // If the Credit Card processing requires a 3D authentication from the consumer, we redirect 
            if (!empty($_SESSION[$this->paymentName]['nn_cc_3d_redirect'])) {
                // Setting up the return URL for the success / error message information (the landing page after customer redirecting back from partner)
                $paymentRequestData['transaction']['return_url']   = ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) ? $this->getNotificationURL($orderHash) : $this->getNotificationURL($orderHash).'&sh=' . $orderHash;
            
                // Do the payment call to Novalnet server
                $paymentResponseData = $this->novalnetPaymentGateway->performServerCall($paymentRequestData, $paymentRequestData['payment_url']);
            }
        }       
        
        $_SESSION['nn_'. $this->paymentName . '_request'] = $paymentRequestData;
        
        // Do redirect if the redirect URL is present
        if (!empty($paymentResponseData['result']['redirect_url']) && !empty($paymentResponseData['transaction']['txn_secret'])) {  

            // Transaction secret used for the later checksum verification
            $_SESSION[$this->paymentName]['novalnet_txn_secret'] = $paymentResponseData['transaction']['txn_secret'];
            
            \header('Location: ' . $paymentResponseData['result']['redirect_url']);
            exit;
        } else {
            if ($this->duringCheckout == 0) {
            
                // Do the payment call to Novalnet server when payment before order completion option is set to 'Nein'            
                $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['nn_'. $this->paymentName . '_request'], $paymentRequestData['payment_url']);
                
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
        $Zahlungsart = isset($_SESSION['Zahlungsart']->nWaehrendBestellung) ? $_SESSION['Zahlungsart']->nWaehrendBestellung : 1;
        // Checksum validation for redirects
        if (!empty($args['tid'])) {
			// Checksum verification and transaction status call to retrieve the full response
            $paymentResponseData = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($order, $args, $this->paymentName);
            $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $paymentResponseData;

			// Evaluating the payment response for the redirected payment
			$this->novalnetPaymentGateway->validatePaymentResponse($order, $paymentResponseData, $this->paymentName);
			if($args['status'] == 'SUCCESS' && $Zahlungsart != 0) {
				if(version_compare(\APPLICATION_VERSION, '5.2.0-beta', '>=')) {
					$orderHandler = new OrderHandler(Shop::Container()->getDB(),Frontend::getCustomer(), Frontend::getCart());
					$order = $orderHandler->finalizeOrder();
				} else {
					$order = finalisiereBestellung();
				}
				if ($order->kBestellung > 0) {
					$this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tzahlungsession', 'cZahlungsID', $hash, ['nBezahlt' => 1, 'dZeitBezahlt' =>  'NOW()', 'kBestellung' => $order->kBestellung]); 
				}
			}
        } else {
			if($Zahlungsart != 0) {
				// Do the payment call to Novalnet server when payment before order completion option is set to 'Nein'
				$paymentResponseData = $this->novalnetPaymentGateway->performServerCall($_SESSION['nn_'. $this->paymentName . '_request'], $_SESSION['nn_'. $this->paymentName . '_request']['payment_url']);
				$_SESSION['nn_'. $this->paymentName .'_payment_response'] = $paymentResponseData;

				// Evaluating the payment response for the redirected payment
				$this->novalnetPaymentGateway->validatePaymentResponse($order, $paymentResponseData, $this->paymentName);
				if(version_compare(\APPLICATION_VERSION, '5.2.0-beta', '>=')) {
					$orderHandler = new OrderHandler(Shop::Container()->getDB(),Frontend::getCustomer(), Frontend::getCart());
					$order = $orderHandler->finalizeOrder();
				} else {
					$order = finalisiereBestellung();
				}
				if ($order->kBestellung > 0) {
					$this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tzahlungsession', 'cZahlungsID', $hash, ['nBezahlt' => 1, 'dZeitBezahlt' =>  'NOW()', 'kBestellung' => $order->kBestellung]); 
				}
			}
        }
        $this->handleNotification($order,  $hash,  $args);  
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
        $tid = !empty($args['tid']) ? $args['tid'] : '';
        $incomingPayment = Shop::Container()->getDB()->select('tzahlungseingang', ['kBestellung', 'cHinweis'], [$order->kBestellung, $tid]);
        if (is_object($incomingPayment) && intval($incomingPayment->kZahlungseingang) > 0) {
            $this->novalnetPaymentGateway->completeOrder($order, $this->paymentName, $this->generateHash($order));
        } else {
            if (isset($_SESSION['Zahlungsart']->nWaehrendBestellung) && $_SESSION['Zahlungsart']->nWaehrendBestellung == 0 && !empty($tid)) {
                // Checksum verification and transaction status call to retrieve the full response
                $paymentResponseData = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($order, $args, $this->paymentName);
                $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $paymentResponseData;
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
        if ($_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['status'] == 'CONFIRMED') {
            if($_SESSION['nn_'. $this->paymentName . '_url'] != 'authorize')  {
                $incomingPayment = $this->novalnetPaymentGateway->novalnetPaymentHelper->incoming_payments($order, $this->paymentName);
            // Add the current transaction payment into db
            $this->addIncomingPayment($order, $incomingPayment);  
            }
            // Update the payment paid time to the shop order table
            $isTransactionPaid = true;
        }
        
        // Updates transaction ID into shop for reference
        $this->updateNotificationID($order->kBestellung, (string) $_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['tid']); 
        
        // Getting the transaction comments to store it in the order 
        $transactionDetails = $this->novalnetPaymentGateway->getTransactionInformation($order, $_SESSION['nn_'.$this->paymentName.'_payment_response'], $this->paymentName);            
        // Setting up the Order Comments and Order status in the order table 
        $orderStatus = $_SESSION['nn_'.$this->paymentName.'_payment_response']['transaction']['status'] == 'ON_HOLD' ? constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status')) : constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $this->paymentName));
            $updateData = [
            'cStatus'    => $orderStatus,
            'cKommentar' => $transactionDetails,
        ];

        if ($_SESSION['nn_' . $this->paymentName . '_url'] != 'authorize') {
            $updateData['dBezahltDatum'] = $isTransactionPaid ? 'NOW()' : '';
        }
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $order->cBestellNr, $updateData);
        // Completing the order based on the resultant status 
        $this->novalnetPaymentGateway->handlePaymentCompletion($order, $this->paymentName, $this->generateHash($order), $transactionDetails);
    }

    /**
     * Collecting the Credit Card for the initial authentication call to PSP
     *
     * @return string
     */
    private function getCreditCardAuthenticationCallData(): string
    {
        $customerDetails = Frontend::getCustomer();
        $authenticationRequest = [
            'client_key'    => $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_client_key'),
            'inline_form'   => ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('form_type', $this->paymentName) == 'on') ? 1 : 0,
            'enforce_3d'    => ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('enforce_option', $this->paymentName) == 'on') ? 1 : 0,
            'test_mode'     => ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('testmode', $this->paymentName) == 'on') ? 1 : 0,
            'first_name'    => ($customerDetails->cVorname != '') ? $customerDetails->cVorname : $customerDetails->cNachname,
            'last_name'     => ($customerDetails->cNachname!= '') ? $customerDetails->cNachname : $customerDetails->cVorname,
            'email'         => $customerDetails->cMail,
            'street'        => $customerDetails->cStrasse,
            'house_no'      => $customerDetails->cHausnummer,
            'city'          => $customerDetails->cOrt,
            'zip'           => $customerDetails->cPLZ,
            'country_code'  => $customerDetails->cLand,
            'amount'        => $this->novalnetPaymentGateway->novalnetPaymentHelper->getOrderAmount(),
            'currency'      => Frontend::getCurrency()->getCode(),
            'lang'          => Text::convertISO2ISO639(Frontend::getInstance()->getLanguage()->getIso())
        ];  
        
        $billingShippingDetails = $this->novalnetPaymentGateway->novalnetPaymentHelper->getRequiredBillingShippingDetails();
        
        if ($billingShippingDetails['billing'] == $billingShippingDetails['shipping']) {
            $authenticationRequest['same_as_billing'] = 1;
        }
        
        return json_encode($authenticationRequest);
    }
    
    /**
     * Retrieves Credit Card form style set in payment configuration and texts present in language files
     * 
     * @return string
     */
    private function getDynamicCreditCardFormFields(): string
    {
        $ccformFields = [];

        $styleConfiguration = ['novalnet_cc_form_label', 'novalnet_cc_form_input', 'novalnet_cc_form_css'];

        foreach ($styleConfiguration as $value) {
            $ccformFields[$value] = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues($value);
        }

        $textFields = ['save_card_data','add_new_card_details', 'remove_card_detail', 'card_detail_removed', 'card_number_ending_details_label', 'card_expires_text', 'credit_card_name', 'credit_card_name_input', 'credit_card_number', 'credit_card_number_input', 'credit_card_date', 'credit_card_date_input', 'credit_card_cvc', 'credit_card_cvc_input', 'credit_card_error', 'remove_card_detail'];

        foreach ($textFields as $value) {
            $ccformFields[$value] = ($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_' . $value));
        }
       
        $encodedFormFields = json_encode($ccformFields);

        return ($encodedFormFields === null && json_last_error() !== JSON_ERROR_NONE) ? '' : $encodedFormFields;
    }     
}
