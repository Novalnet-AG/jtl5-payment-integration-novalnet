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
 * Script: NovalnetPaymentGateway.php
 *
*/

namespace Plugin\jtl_novalnet\paymentmethod;

use JTL\Shop;
use Plugin\jtl_novalnet\NovalnetPaymentHelper;
use JTL\Session\Frontend;
use JTL\Cart\CartHelper;
use JTL\Plugin\Payment\Method;
use JTL\Checkout\Bestellung;
use JTL\Checkout\Lieferadresse;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Rechnungsadresse;
use JTL\Customer\Customer;
use JTL\Plugin\Helper;
use JTL\Helpers\Request;
use JTL\Catalog\Currency;
use JTL\Helpers\Text;
use JTL\Alert\Alert;
use JTLShop\SemVer\Version;
use stdClass;

/**
 * Class NovalnetPaymentGateway
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetPaymentGateway
{
    /**
     * NovalnetPaymentGateway constructor.
     * 
     * @var NovalnetPaymentHelper
     */
    public $novalnetPaymentHelper;
    
    
    public function __construct()
    {
       $this->novalnetPaymentHelper = new NovalnetPaymentHelper();        
    }
    
    /**
     * Checks the required payment activation configurations
     *
     * return bool
     */
    public function canPaymentMethodProcessed($paymentName): bool
    {
        return ($this->novalnetPaymentHelper->getConfigurationValues('enablemode', $paymentName) && ($this->novalnetPaymentHelper->getConfigurationValues('novalnet_public_key') != '' && $this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key') != '' && $this->novalnetPaymentHelper->getConfigurationValues('novalnet_tariffid') != ''));
    }
    
    /**
     * Checks the required conditions to process the guaranteed / instalment payment methods
     *
     * @param string $paymentName
     *   
     * @return bool
     */
    public function isGuaranteedPaymentAllowed($paymentName): bool
    {        
        // First we check if the billing and shipping addresses matched     
        $billingShippingDetails = $this->novalnetPaymentHelper->getRequiredBillingShippingDetails();
        
        if ($billingShippingDetails['billing'] != $billingShippingDetails['shipping']) {
            return false;
        }
        
         $customerDetails = Frontend::getCustomer();

        // Second, we check if the billing country belongs to the supported countries - B2B customer
        if (($this->novalnetPaymentHelper->getConfigurationValues('allow_b2b_customer', $paymentName) && !(in_array($billingShippingDetails['billing']['country_code'], $this->novalnetPaymentHelper->getEuropeanRegionCountryCodes())))) {
            return false;
        }
        
        // Third, we check if the billing country belongs to the company - B2B customer
        if (($this->novalnetPaymentHelper->getConfigurationValues('allow_b2b_customer', $paymentName) && empty($customerDetails->cFirma) && !(in_array($billingShippingDetails['billing']['country_code'], ['DE', 'AT', 'CH'])))) {
            return false;
        }


        // B2C customer
        if (($this->novalnetPaymentHelper->getConfigurationValues('allow_b2b_customer', $paymentName) != 'on' && !(in_array($billingShippingDetails['billing']['country_code'], ['DE', 'AT', 'CH'])))) {
            return false;
        }

        // Third, we check if the currency is matched. Currently only "EUR" is supported
        if (Frontend::getCurrency()->getCode() != 'EUR') {
            return false;
        }
    
        // Lastly, we check if the minimum amount configured to allow this payment method is met        
        // By default, the minimum is 9,99 EUR for guarantee or 19,98 EUR for minimum two cycles of Instalment type 
        $minimumAmountForGuaranteeOrInstalment = strpos($paymentName, 'instalment') ? 1998 : 999;
        
        // We also check if there is any minimum amount configured for the payment type 
        $configuredMinimumAmount = $this->novalnetPaymentHelper->getConfigurationValues('min_amount', $paymentName);    

        if ((!empty($configuredMinimumAmount) && $configuredMinimumAmount >= $minimumAmountForGuaranteeOrInstalment)) {
            $minimumAmountForGuaranteeOrInstalment = $configuredMinimumAmount;
        }       

        // We compare with the order gross amount if the minimum amount condition is satisfied
        $orderAmount = $this->novalnetPaymentHelper->getOrderAmount();
        if ($orderAmount < $minimumAmountForGuaranteeOrInstalment) {
            return false;
        }           
        
        // For the instalment payment types, each of the instlament cycles has to be met with the minimum condition of 9,99 EUR
        if (strpos($paymentName, 'instalment')) {
            $instalmentCycles = $this->novalnetPaymentHelper->getConfigurationValues('cycles', $paymentName);
            
            if (!empty($instalmentCycles)) {
                // Looping in the configured cycles and checking each of the cycles
                foreach ($instalmentCycles as $key => $value) {
                    $instalmentCycleAmount = ($orderAmount / $value);
                    if ($instalmentCycleAmount > 999) {
                        return true;
                    }
                }
            }
            return false;
        }
        
        // If every condition is satisfied, the guarantee or Instalment payment is allowed to process
        return true;        
    }
    
    /**
     * Check if the guaranteed conditions are met, guaranteed or normal payment method needs to be displayed 
     *
     * @param string $paymentName
     * @return bool
     */
    public function checkIfGuaranteePaymentHasToBeDisplayed(string $paymentName): string
    {               
        // Checking if the guarantee payment method is activated in the shop backend 
        if ($this->novalnetPaymentHelper->getConfigurationValues('enablemode', $paymentName)) {
            
            // If the guaranteed conditions are met, guaranteed payment method will be displayed suppressing the normal one
            if ($this->isGuaranteedPaymentAllowed($paymentName)) {
                
                // We check if the birthDay value is given already it is below 18 years and Force non-guarantee is enabled proceed the invoice payment method
                $customerDetails = Frontend::getCustomer();
                $isBirthDayValid = (empty($customerDetails->cFirma) && !empty($customerDetails->dGeburtstag) && (time() < strtotime('+18 years', strtotime($customerDetails->dGeburtstag)))) ? true : false;
                if ($this->novalnetPaymentHelper->getConfigurationValues('force', $paymentName) == 'on' && $isBirthDayValid) {
                    return 'normal';
                } elseif (empty($this->novalnetPaymentHelper->getConfigurationValues('force', $paymentName)) && $isBirthDayValid) {
                    return 'error';
                } else {
                    return 'guarantee';
                }
            }
            
            // Further we check if the normal payment method can be enabled if the condition not met 
            if ($this->novalnetPaymentHelper->getConfigurationValues('force', $paymentName)) {
                return 'normal';
            }

            // If none matches, error message displayed 
            return 'error';           
        }
        
        // If payment guarantee is not enabled, we show default one 
        return 'normal';
    }
    
    /**
     * Build payment parameters to server
     *
     * @param  object $order
     * @param  string $paymentName
     * @return array
     */
    public function generatePaymentParams(object $order, string $paymentName): array
    {
        $paymentRequestData = [];
        
        // Extracting the customer 
        $customerDetails = Frontend::getCustomer();
        
        // Building the merchant Data
        $paymentRequestData['merchant'] = [
                                            'signature'    => $this->novalnetPaymentHelper->getConfigurationValues('novalnet_public_key'),
                                            'tariff'       => $this->novalnetPaymentHelper->getConfigurationValues('novalnet_tariffid'),
                                          ];
        
        // Building the customer Data
        $paymentRequestData['customer'] = [
                                            'first_name'   => !empty($customerDetails->cVorname) ? $customerDetails->cVorname : $customerDetails->cNachname,
                                            'last_name'    => !empty($customerDetails->cNachname) ? $customerDetails->cNachname : $customerDetails->cVorname,
                                            'gender'       => !empty($customerDetails->cAnrede) ? $customerDetails->cAnrede : 'u',
                                            'email'        => $customerDetails->cMail,
                                            'customer_no'  => !empty($customerDetails->cKundenNr) ? $customerDetails->cKundenNr : (!empty($customerDetails->kKunde) ? $customerDetails->kKunde : 'guest'),
                                            'customer_ip'  => $this->novalnetPaymentHelper->getNnIpAddress('REMOTE_ADDR')
                                          ];
        
        if (!empty($customerDetails->cTel)) { // Check if telephone field is given
            $paymentRequestData['customer']['tel'] = $customerDetails->cTel;
        }
        
        if (!empty($customerDetails->cMobil)) { // Check if mobile field is given
            $paymentRequestData['customer']['mobile'] = $customerDetails->cMobil;
        }
        
        // Extracting the required billing and shipping details from the customer session object        
        $billingShippingDetails = $this->novalnetPaymentHelper->getRequiredBillingShippingDetails();
        
        $paymentRequestData['customer'] = array_merge($paymentRequestData['customer'], $billingShippingDetails);
        
        // If the billing and shipping are equal, we notify it too 
        if ($paymentRequestData['customer']['billing'] == $paymentRequestData['customer']['shipping']) {
            $paymentRequestData['customer']['shipping']['same_as_billing'] = '1';
        }
        
        if (!empty($customerDetails->cFirma)) { // Check if company field is given in the billing address
            $paymentRequestData['customer']['billing']['company'] = $customerDetails->cFirma;
        }
        
        if (!empty($_SESSION['Lieferadresse']->cFirma)) { // Check if company field is given in the shipping address
            $paymentRequestData['customer']['shipping']['company'] = $_SESSION['Lieferadresse']->cFirma;
        }
        
        if (!empty($customerDetails->cBundesland)) { // Check if state field is given in the billing address
            $paymentRequestData['customer']['billing']['state'] = $customerDetails->cBundesland;
        }
        
        if (!empty($_SESSION['Lieferadresse']->cBundesland)) { // Check if state field is given in the shipping address
            $paymentRequestData['customer']['shipping']['state'] = $_SESSION['Lieferadresse']->cBundesland;
        }
        
        // Building the transaction Data
        $paymentRequestData['transaction'] = [
                                               'test_mode' => !empty($this->novalnetPaymentHelper->getConfigurationValues('testmode', $paymentName)) ? 1 : 0,
                                               'amount'    => $this->novalnetPaymentHelper->getOrderAmount($order),
                                               'currency'  => Frontend::getCurrency()->getCode(),
                                               'system_name'   => 'jtlshop',
                                               'system_version' => Version::parse(APPLICATION_VERSION)->getOriginalVersion() . '_NN12.2.1',
                                               'system_url' => Shop::getURL(),
                                               'system_ip'  => $this->novalnetPaymentHelper->getNnIpAddress('SERVER_ADDR')
                                             ];
        
        // If the order generation is done before the payment completion, we get the order number in the initial call itself
        if (isset($_SESSION['Zahlungsart']->nWaehrendBestellung) && $_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {
            $paymentRequestData['transaction']['order_no'] = $order->cBestellNr;
        } 
        // Send the order language
        $paymentRequestData['custom']['lang'] = (!empty($_SESSION['cISOSprache']) && $_SESSION['cISOSprache'] == 'ger') ? 'DE' : 'EN';  
        // Unset the billing and shipping house number if it is empty
        if(empty($paymentRequestData['customer']['billing']['house_no'])) {
            unset($paymentRequestData['customer']['billing']['house_no']);
        }
        if(empty($paymentRequestData['customer']['shipping']['house_no'])) {
            unset($paymentRequestData['customer']['shipping']['house_no']);
        }         
        
        return $paymentRequestData;
    }
    
    /**
     * Returns with error message on failure cases 
     *
     * @param  object  $order
     * @param  array   $paymentRequestData
     * @param  string  $paymentName 
     * @param  string  $explicitErrorMessage 
     * @return none
     */
    public function redirectOnError(object $order, array $paymentResponse, string $paymentName, string $explicitErrorMessage = '')
    {
        // Set the error message from the payment response
        $errorMsg = (!empty($paymentResponse['result']['status_text']) ? $paymentResponse['result']['status_text'] : $paymentResponse['status_text']);
        
        // If the order has been created already and if the order has to be closed 
        $Zahlungsart = isset($_SESSION['Zahlungsart']->nWaehrendBestellung) ? $_SESSION['Zahlungsart']->nWaehrendBestellung : 1;
        if ($Zahlungsart == 0) {
            
            // Building the transaction comments for the failure case 
            $transactionComments = $this->getTransactionInformation($order, $paymentResponse, $paymentName);
            
            // Setting up the cancellation status in the database for the order 
            $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $order->kBestellung, ['cStatus' => \BESTELLUNG_STATUS_STORNO, 'cAbgeholt' => 'Y', 'cKommentar' => $transactionComments . \PHP_EOL . $errorMsg]);
            
            $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
            
            // Triggers cancellation mail template
            $jtlPaymentmethod->sendMail($order->kBestellung, \MAILTEMPLATE_BESTELLUNG_STORNO);
            
            $txAdditonalInfo['cKommentar'] = $transactionComments;
            // logs the details into novalnet db for failure
            $this->insertOrderDetailsIntoNnDb($order, $paymentResponse, $paymentName, $txAdditonalInfo);

            // Clear the shop and novalnet session
            Frontend::getInstance()->cleanUp(); // unset the shop session
            $this->novalnetPaymentHelper->novalnetSessionCleanUp($paymentName); // unset novalnet session
            
            // Redirecting to the order page in the account section 
            \header('Location:' . $order->BestellstatusURL);
            exit;
        }
        
        // If the order has to be continued, we display the error in the payment page and the payment process is continued 
        $errorMessageToDisplay = !empty($explicitErrorMessage) ? $explicitErrorMessage : $errorMsg;
        
        // Setting up the error message in the shop variable 
        $alertHelper = Shop::Container()->getAlertService();        
        $alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessageToDisplay, 'display error on payment page', ['saveInSession' => true]);        
        
        // Redirecting to the checkout page 
		if($_SESSION['cISOSprache'] == 'eng'){
			\header('Location:' . Shop::getURL() . '/Checkout?editVersandart=1');
			exit;
		}
		\header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
		exit;
    }
    
    /**
     * To get the Novalnet SEPA duedate in days
     *
     * @param  integer $dueDate
     * @return string
     */
    public function getSepaDuedate($dueDate): string
    {
		$dueDate = !empty($dueDate) ? $dueDate : 0;
        return (preg_match('/^[0-9]*$/', $dueDate) && $dueDate > 1) ? date('Y-m-d', strtotime('+' . $dueDate . 'days')) : '';
    }

    /**
     * Make CURL payment request to Novalnet server
     *
     * @param  string $paymentRequestData
     * @param  string $paymentUrl
     * @param  string $paymentAccessKey
     * @return array
     */
    public function performServerCall(array $paymentRequestData, $paymentUrl, $paymentAccessKey = ''): array
    {
        // Based on the request type, retrieving the payment request URL to make the API call
        $paymentUrl = $this->getApiRequestURL($paymentUrl);
        
        // Payment Access Key that can be found in the backend is an imporant information that needs to be sent in header for merchant validation 
        $paymentAccessKey = !empty($paymentAccessKey) ? $paymentAccessKey : $this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key');

        // Setting up the important information in the headers 
        $headers = [
                     'Content-Type:application/json',
                     'charset:utf-8',
                     'X-NN-Access-Key:'. base64_encode($paymentAccessKey),
                   ];
        
        // Initialization of the cURL 
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, $paymentUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($paymentRequestData));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // Execute cURL
        $paymentResponse = curl_exec($curl);

        // Handle cURL error
        if (curl_errno($curl)) {
           $logger   = Shop::Container()->getLogService();
           $logger->error('Request Error:' . curl_error($curl));
        }
        
        // Close cURL
        curl_close($curl);
        
        // Decoding the JSON string to array for further processing 
        return json_decode($paymentResponse, true);
    }
    
    /**
     * Get payment request URL's based on the request type 
     *
     * @param  string $paymentUrl
     * @return string
     */
    public function getApiRequestURL(string $apiType): string
    { 
        // Novalnet's v2 interface base URL 
        $baseUrl = 'https://payport.novalnet.de/v2/';
        
        // Adding up the suffix based on the request type 
        $suffixUrl = strpos($apiType, '_') !== false ? str_replace('_', '/', $apiType) : $apiType;
        
        // Returning the payment URL for the API call 
        return $baseUrl . $suffixUrl;
    }
    
    /**
     * Validates the manual check limit of the configured payment method 
     *
     * @param int $orderAmount
     * @param string $paymentName
     * @return bool
     */
    public function isTransactionRequiresAuthorizationOnly(int $orderAmount, string $paymentName) : bool
    {    
        // First the authorize option selection is verified
        if ($this->novalnetPaymentHelper->getConfigurationValues('payment_action', $paymentName)) {
            
           // Limit for the manual on-hold is compared with the order amount 
           $manualCheckLimit = $this->novalnetPaymentHelper->getConfigurationValues('manual_check_limit', $paymentName);
           
           // "Authorization" activated if the manual limit is configured and the order amount exceeds it 
           if (!empty($manualCheckLimit)) {
               return (preg_match('/^[0-9]*$/', $manualCheckLimit) && $orderAmount > $manualCheckLimit);
            }

           return true;        
        }
       
        // By default, we keep the "Capture Immediate" as the chosen type 
        return false;
    }
        
    
    /**
     * Validates the novalnet payment response
     *
     * @param  object $order
     * @param  array $paymentResponse
     * @param  array $paymentName
     * @return none|bool
     */
    public function validatePaymentResponse(object $order, array $paymentResponse, string $paymentName): bool
    {
        // Building the failure transaction comments
        $transactionComments = $this->getTransactionInformation($order, $paymentResponse, $paymentName);
        
        // Routing if the result is a failure 
        if (!empty($paymentResponse['result']['status']) && $paymentResponse['result']['status'] != 'SUCCESS') {
            $nWaehrendBestellungZahlungsart = isset($_SESSION['Zahlungsart']->nWaehrendBestellung) ? $_SESSION['Zahlungsart']->nWaehrendBestellung : 1;
            if ($nWaehrendBestellungZahlungsart == 0) {
                // Logs the order details in Novalnet tables for failure
                $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $order->kBestellung, ['cKommentar' => $transactionComments]);
            }
            $this->redirectOnError($order, $paymentResponse, $paymentName);
            
        } else {
            $nWaehrendBestellung = isset($_SESSION['Zahlungsart']->nWaehrendBestellung) ? $_SESSION['Zahlungsart']->nWaehrendBestellung : 0;
            if ($nWaehrendBestellung == 1) {
                if (!empty($paymentResponse['transaction']['bank_details']) && ((in_array($paymentResponse['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) || in_array($paymentResponse['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT')))) {
                    $transactionComments .= $this->getBankdetailsInformation($order, $paymentResponse);
                }
                if (!empty($paymentResponse['transaction']['payment_type'] == 'CASHPAYMENT')) {
                    $transactionComments .= $this->getStoreInformation($paymentResponse);
                }
            }
            
            $_SESSION['nn_comments'] = $transactionComments;
            return true;
        }
    }
    
    /**
     * Process while handling handle_notification URL
     *
     * @param  array  $order
     * @param  string $paymentName
     * @param  string $sessionHash
     * @return none
     */
    public function handlePaymentCompletion(object $order, string $paymentName, string $sessionHash, string $transactionDetails = null): void
    {        
        $paymentResponse = $_SESSION['nn_'. $paymentName .'_payment_response'];
        if (!empty($paymentResponse['result']['status'])) {  
        
            // Success result handling 
            if ($paymentResponse['result']['status'] == 'SUCCESS') {
                
                // If the payment is already done and order created, we send update order email 
                if ($order->Zahlungsart->nWaehrendBestellung == 0) {
                    $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
                    
                    // Triggers order update mail template
                    $jtlPaymentmethod->sendMail($order->kBestellung, \MAILTEMPLATE_BESTELLUNG_AKTUALISIERT);
                
                } else {
                    $paymentRequestData = [];
                    $paymentRequestData['transaction'] = [
                                                            'tid' => $paymentResponse['transaction']['tid'],
                                                            'order_no' => $order->cBestellNr
                                                         ];
                    $transactionUpdateResponse = $this->performServerCall($paymentRequestData, 'transaction_update');
                    
                    if ((in_array($transactionUpdateResponse['transaction']['payment_type'], array('INSTALMENT_INVOICE', 'GUARANTEED_INVOICE')) && in_array($transactionUpdateResponse['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) ||  in_array($transactionUpdateResponse['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT'))) {
                        $transactionDetails = $this->getTransactionInformation($order, $transactionUpdateResponse, $paymentName);
                       
                        $transactionDetails .= $this->getBankdetailsInformation($order, $transactionUpdateResponse); 
                        $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
						// Triggers order update mail template
						$jtlPaymentmethod->sendMail($order->kBestellung, \MAILTEMPLATE_BESTELLUNG_AKTUALISIERT);
                        $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung',  $order->kBestellung, ['cKommentar' => $transactionDetails]);
                    }
 
                }
				$txAdditonalInfo['cKommentar'] = $transactionDetails;
                // Inserting order details into Novalnet table 
                $this->insertOrderDetailsIntoNnDb($order, $paymentResponse, $paymentName, $txAdditonalInfo);
		
                $updateWawi = 'Y';
                
                // Update the WAWI pickup status as 'Nein' for confirmed transaction
                if ($paymentResponse['transaction']['status'] == 'CONFIRMED' || (in_array($paymentResponse['transaction']['payment_type'], ['INVOICE', 'PREPAYMENT']) && $paymentResponse['transaction']['status'] == 'PENDING')) {
                    $updateWawi = 'N';
                }
                
                // Updates the value into the database                
                $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung',  $order->kBestellung, ['cAbgeholt' => $updateWawi]); 
                
                // Unset the entire novalnet session on order completion
                $this->novalnetPaymentHelper->novalnetSessionCleanUp($paymentName);
               
                \header('Location: ' . Shop::Container()->getLinkService()->getStaticRoute('bestellabschluss.php') .
                '?i=' . $sessionHash);
                exit;
            } else {
                $this->redirectOnError($order,  $paymentResponse, $paymentName);
            }
        } else {
            \header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
            exit;
        }        
    }
    
    /**
     * Setting up the transaction details for storing in the order 
     *
     * @param  object $order
     * @param  array  $paymentResponse
     * @param  string $paymentName
     * @return string
     */
    public function getTransactionInformation(object $order, array $paymentResponse, string $paymentName): string
    {
        $transactionComments = '';

        if(!empty($_SESSION['cPost_arr']['kommentar'])) {
            $userComments = strip_tags($_SESSION['cPost_arr']['kommentar']);
        }
        
        if(!empty($userComments)) {
            $transactionComments .= $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_customer_comment') . $userComments . \PHP_EOL;
        }
        
        $transactionComments .= $order->cZahlungsartName;

        // Set the Novalnet transaction id based on the response
        $novalnetTxTid = !empty($paymentResponse['transaction']['tid']) ? $paymentResponse['transaction']['tid'] : $paymentResponse['tid'];
        
        if(!empty($novalnetTxTid)) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_transaction_tid') . ': '. $novalnetTxTid;
        }
        
        // Set the Novalnet transaction mode based on the response
        $novalnetTxMode = !empty($paymentResponse['transaction']['test_mode']) ? $paymentResponse['transaction']['test_mode'] : '';
        
        if(!empty($novalnetTxMode)) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_test_order');
        } 
        
        if (in_array($paymentName, ['novalnet_guaranteed_invoice', 'novalnet_instalment_invoice'])  && $paymentResponse['transaction']['status'] == 'PENDING') {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guaranteed_invoice_pending_text');
        }
        
        if (in_array($paymentName, ['novalnet_guaranteed_sepa', 'novalnet_instalment_sepa']) && $paymentResponse['transaction']['status'] == 'PENDING') {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guaranteed_sepa_pending_text');
        }
        
        return $transactionComments;
    }
    
    /**
     * Setting up the Invoice bank details for storing in the order 
     *
     * @param  object $order
     * @param  array  $paymentResponse
     * @return string
     */
    public function getBankdetailsInformation(object $order, array $paymentResponse, $lang = null): string
    {
        $amount = ($paymentResponse['transaction']['payment_type'] == 'INSTALMENT_INVOICE') ? $paymentResponse['instalment']['cycle_amount'] : $paymentResponse['transaction']['amount'];  
		$invoiceComments = '';
        if(!empty($order->kBestellung)) {
			$totalamount = $this->convertCurrencyFormatter($order->kBestellung, $amount);
			
			if ($paymentResponse['transaction']['status'] != 'ON_HOLD') {
				$invoiceComments = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payment_transfer_duedate_comments', $lang), $totalamount, date('d.m.Y', strtotime($paymentResponse['transaction']['due_date'])));
			} else {
				$invoiceComments = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payment_transfer_comment', $lang), $totalamount);
			}   
		}
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_holder', $lang) . $paymentResponse['transaction']['bank_details']['account_holder'];
        $invoiceComments .= \PHP_EOL . 'IBAN: ' . $paymentResponse['transaction']['bank_details']['iban'];
        $invoiceComments .= \PHP_EOL . 'BIC: ' . $paymentResponse['transaction']['bank_details']['bic'];
        $invoiceComments .= \PHP_EOL . 'BANK: ' . $paymentResponse['transaction']['bank_details']['bank_name'] . ' ' . $paymentResponse['transaction']['bank_details']['bank_place'];        
        
        // Adding the payment reference details 
		$invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_single_reference_text', $lang);
        $firstPaymentReference = ($paymentResponse['transaction']['payment_type'] == 'INSTALMENT_INVOICE') ? 'jtl_novalnet_instalment_payment_reference' : 'jtl_novalnet_invoice_payments_first_reference';
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation($firstPaymentReference, $lang) . $paymentResponse['transaction']['tid'];
        if (!empty($paymentResponse['transaction']['invoice_ref']) && $paymentResponse['transaction']['payment_type'] != 'INSTALMENT_INVOICE') {
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_second_reference', $lang) . $paymentResponse['transaction']['invoice_ref'];
        }
        
            
        return $invoiceComments;
    }
    
    /**
     * Setting up the Cashpayment store details for storing in the order 
     *
     * @param array $paymentResponse
     * @return string
     */
    public function getStoreInformation(array $paymentResponse): string
    {        
        $cashpaymentComments  = \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_expiry_date') . date('d.m.Y', strtotime($paymentResponse['transaction']['due_date'])) . \PHP_EOL;
        $cashpaymentComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_nearest_store_details') . \PHP_EOL;
        
        // There would be a maximum of three nearest stores for the billing address
        $nearestStores = $paymentResponse['transaction']['nearest_stores'];
        
        // We loop in each of them to print those store details 
        for ($storePos = 1; $storePos <= count($nearestStores); $storePos++) {
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['store_name'];
            $cashpaymentComments .= \PHP_EOL . utf8_encode($nearestStores[$storePos]['street']);
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['city'];
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['zip'];
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['country_code'];
            $cashpaymentComments .= \PHP_EOL;
        }
        
        return $cashpaymentComments;
    }        
    
    /**
     * To insert the order details into Novalnet tables
     *
     * @param  object $order
     * @param  array  $paymentResponse
     * @param  string $paymentName
     * @return none
     */
    public function insertOrderDetailsIntoNnDb(object $order, array $paymentResponse, string $paymentName, array $txAdditonalInfo = null): void
    {
        $customerDetails = Frontend::getCustomer();
        
        if (!empty($paymentResponse['transaction']['payment_data']['token'])) {
            $name = (in_array($paymentName, array('novalnet_sepa', 'novalnet_guaranteed_sepa', 'novalnet_instalment_sepa'))) ? 'sepa' : $paymentName;
            $this->saveRecentOrderPaymentData($name, $paymentResponse['transaction']['payment_data']);
        }
        
        $insertOrder = new stdClass();
        $insertOrder->cNnorderid         = $order->cBestellNr;
        $insertOrder->nNntid             = !empty($paymentResponse['transaction']['tid']) ? $paymentResponse['transaction']['tid'] : $paymentResponse['tid'];
        $insertOrder->cZahlungsmethode   = $paymentName;
        $insertOrder->cMail              = $customerDetails->cMail;
        $insertOrder->cStatuswert        = !empty($paymentResponse['transaction']['status']) ? $paymentResponse['transaction']['status'] : $paymentResponse['status'];
        $insertOrder->nBetrag            = !empty($paymentResponse['instalment']['total_amount']) ? $paymentResponse['instalment']['total_amount'] : (!empty($paymentResponse['transaction']['amount']) ? $paymentResponse['transaction']['amount'] : (round($order->fGesamtsumme) * 100));
        $insertOrder->cSaveOnetimeToken  = !empty($paymentResponse['transaction']['payment_data']['token']) ? 1 : 0;
        $insertOrder->cTokenInfo         = !empty($paymentResponse['transaction']['payment_data']) ? json_encode($paymentResponse['transaction']['payment_data']) : '';
        $instalmentData 				 = isset($paymentResponse['instalment']) ? (array) $paymentResponse['instalment'] : [];
        $insertOrder->cAdditionalInfo    = json_encode(array_merge($instalmentData, (array) $txAdditonalInfo));
        Shop::Container()->getDB()->insert('xplugin_novalnet_transaction_details', $insertOrder);
    }
    
    /**
     * To save the recent order payment details into Novalnet tables
     *
     * @param  string $paymentName
     * @param  array  $responsePaymentData
     * @return none
     */
    public function saveRecentOrderPaymentData (string $paymentName, array $responsePaymentData): void
    {
        $getPaymentData = Shop::Container()->getDB()->queryPrepared('SELECT nNntid, cTokenInfo FROM xplugin_novalnet_transaction_details WHERE cSaveOnetimeToken = :cSaveOnetimeToken and cTokenInfo != :cTokenInfo and cZahlungsmethode LIKE :paymentName',[':cSaveOnetimeToken' => '1', ':cTokenInfo' => '', ':paymentName' => '%'. $paymentName .''], 2);
        
        foreach ($getPaymentData as $paymentData) {
            $tokenInfo = json_decode($paymentData->cTokenInfo, true);
            
            if ($paymentName == 'sepa' && $tokenInfo['iban'] == $responsePaymentData['iban'] || ($paymentName == 'novalnet_paypal' && $tokenInfo['paypal_account'] == $responsePaymentData['paypal_account']) || ($paymentName == 'novalnet_cc' && $tokenInfo['card_number'] == $responsePaymentData['card_number']  && $tokenInfo['card_expiry_month'] == $responsePaymentData['card_expiry_month'] && $tokenInfo['card_expiry_year'] == $responsePaymentData['card_expiry_year']) ) {
                 $this->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'nNntid', $paymentData->nNntid, ['cTokenInfo' => '']);
            }
        }
    }
    
    /**
     * Complete the order
     *
     * @param  object  $order
     * @param  string $paymentName
     * @param  string $sessionHash
     * @return none
     */
    public function completeOrder(object $order, string $paymentName, string $sessionHash): void
    {     
        $paymentResponse = $_SESSION['nn_'. $paymentName .'_payment_response'];
        
        if ($paymentResponse) {
            // If the order is already complete, we do the appropriate action 
            if ($paymentResponse['result']['status'] == 'SUCCESS') {
                        
                // Unset the entire novalnet session on order completion
                $this->novalnetPaymentHelper->novalnetSessionCleanUp($paymentName);
            
                // Routing to the order page from my account for the order completion 
                \header('Location: ' . Shop::Container()->getLinkService()->getStaticRoute('bestellabschluss.php') . '?i=' . $sessionHash);
                exit;
            } else {
                // Returns with error message on error
                $this->redirectOnError($order, $paymentResponse, $paymentName); 
            }
        }
    }
    
    /**
     * Compare the checksum generated for redirection payments
     *
     * @param  object  $$order
     * @param  array  $paymentResponse
     * @param  string $paymentName
     * @return array
     */
    public function checksumValidateAndPerformTxnStatusCall(object $order, array $paymentResponse, string $paymentName): array
    {
        if ($paymentResponse['status'] && $paymentResponse['status'] == 'SUCCESS') {
            
            // Condition to check whether the payment is redirect
            if (!empty($paymentResponse['checksum']) && !empty($paymentResponse['tid']) && !empty($_SESSION[$paymentName]['novalnet_txn_secret'])) {
                                            
                $generatedChecksum = hash('sha256', $paymentResponse['tid'] . $_SESSION[$paymentName]['novalnet_txn_secret'] . $paymentResponse['status'] . strrev($this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key')));
                
                // If the checksum isn't matching, there could be a possible manipulation in the data received 
                if ($generatedChecksum !== $paymentResponse['checksum']) {                                  
                    $explicitErrorMessage = $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_checksum_error');
                    
                    // Redirects to the error page
                    $this->redirectOnError($order, $paymentResponse, $paymentName, $explicitErrorMessage); 
                }
            }
                                          
            $transactionDetailsRequest = [];
            $transactionDetailsRequest['transaction']['tid'] = $paymentResponse['tid'];
                
            return $this->performServerCall($transactionDetailsRequest, 'transaction_details');         
            
        } else {
            // Redirects to the error page
            $this->redirectOnError($order, $paymentResponse, $paymentName); 
        }                   
    } 
    
    /**
     * Retrieve the Instalment information from the database 
     *
     * @param int $orderNo
     * @return array|null
     */
    public function getInstalmentInfoFromDb($orderNo, $lang=null, $orderId=null): ?array
    {
        $transactionDetails = Shop::Container()->getDB()->queryPrepared('SELECT nov.nNntid, nov.cStatuswert, nov.nBetrag, nov.cAdditionalInfo  FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov ON ord.cBestellNr = nov.cNnorderid WHERE cNnorderid = :cNnorderid and nov.cZahlungsmethode LIKE :novalnet_instalment', [':cNnorderid' => $orderNo, ':novalnet_instalment' => 'novalnet_instalment%'], 1);
        
        if (!empty($transactionDetails) && $transactionDetails->cStatuswert == 'CONFIRMED') {
            $insAdditionalInfo = json_decode($transactionDetails->cAdditionalInfo, true);

            $instalmentInfo = [];
            $totalInstalments = count($insAdditionalInfo['cycle_dates']);
            $insAdditionalInfo[1]['tid'] = $transactionDetails->nNntid;
            
            foreach($insAdditionalInfo['cycle_dates'] as $key => $instalmentCycleDate) {
                $instalmentCycle[$key] = $instalmentCycleDate;
            }
            $instalment_cycle_cancel = '';
            // Instalment Status
            if (isset($insAdditionalInfo['is_full_instalment_cancel'])) {
                $instalment_cycle_cancel = $insAdditionalInfo['is_full_instalment_cancel'];
            }
            for($instalment=1;$instalment<=$totalInstalments;$instalment++) {
                if($instalment != $totalInstalments) {
				$instalmentAmount = $this->convertCurrencyFormatter($orderId, ($insAdditionalInfo['cycle_amount']));
                $instalmentInfo['insDetails'][$instalment]['cycle_amount'] = $instalmentAmount;
                } else {
                    $cycleAmount = ($transactionDetails->nBetrag - ($insAdditionalInfo['cycle_amount'] * ($instalment - 1)));
                    $instalmentAmount = $this->convertCurrencyFormatter($orderId, $cycleAmount);
                    $instalmentInfo['insDetails'][$instalment]['cycle_amount'] = $instalmentAmount;
                }
                $instalmentInfo['insDetails'][$instalment]['tid'] = !empty($insAdditionalInfo[$instalment]['tid']) ?  $insAdditionalInfo[$instalment]['tid'] : '-';
                $instalmentInfo['insDetails'][$instalment]['payment_status'] = ($instalment_cycle_cancel == 'all_cycles') ? ($lang == 'ger' ? 'Storno' : 'Cancelled') : (($instalmentInfo['insDetails'][$instalment]['tid'] != '-') ? ($lang == 'ger' ? 'Bezahlt' : 'Paid') : ($instalment_cycle_cancel == 'remaining_cycles' ? ($lang == 'ger' ? 'Storno' : 'Cancelled') : ($lang == 'ger' ? 'Offen' : 'Open')));
                if(($instalment) != ($totalInstalments)) {
					$instalmentInfo['insDetails'][$instalment]['future_instalment_date'] = date("d.m.Y", strtotime($instalmentCycle[$instalment + 1]));
				}
            }

            $instalmentInfo['lang'] = $this->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_serial_no', 'jtl_novalnet_instalment_future_date', 'jtl_novalnet_instalment_information', 'jtl_novalnet_instalment_amount', 'jtl_novalnet_transaction_tid'], $lang);
             $instalmentInfo['status'] = $transactionDetails->cStatuswert;
            
            return $instalmentInfo;
        }
        
        return null;
    }
    
    /**
     * Form the required wallet payment data 
     *
     * @param string $paymentName
     * @return array|string
     */
    public function getArticleDetails(string $paymentName,  bool $isPaypalcart = false): ?string
    {
		// Get article details
		$cartDetails = Frontend::getCart();
		$currencyConversionFactor = Frontend::getCurrency()->getConversionFactor();
		$taxAmount = $vatName = $totalProductAmount = $convertedShippingPrice = $couponAmount = 0;
		$cartInfo = array();
		
		// Load the line items
		$positionArr = (array) $cartDetails->PositionenArr;
		if (!empty($positionArr)) {
			foreach($positionArr as $positionDetails) {
				if (!empty($positionDetails->kArtikel)) {
					$productName = !empty($positionDetails->Artikel->cName) ? html_entity_decode($positionDetails->Artikel->cName) : html_entity_decode($positionDetails->cName);
					$productQuantity = !empty($positionDetails->Artikel->nAnzahl) ? $positionDetails->Artikel->nAnzahl : $positionDetails->nAnzahl;
					$productDescription = !empty($positionDetails->Artikel->cKurzBeschreibung) ? html_entity_decode($positionDetails->Artikel->cKurzBeschreibung) : html_entity_decode($positionDetails->cKurzBeschreibung);
					$perProductTaxAmount = ($positionDetails->Artikel->Preise->fVKNetto * ($positionDetails->Artikel->taxData['tax'] / 100));
				    $productAmount = ($positionDetails->Artikel->Preise->fVKNetto + $perProductTaxAmount) * $productQuantity;
				    $perProductAmount = ($positionDetails->Artikel->Preise->fVKNetto + $perProductTaxAmount);
				    $totalProductAmount += round(($currencyConversionFactor * $productAmount) * 100);
					if ($isPaypalcart) {
						$cartInfo['line_items'][] = array (
							'name' => $productName,
							'price' => (int) strval(round(($currencyConversionFactor * $productAmount), 2) * 100),
							'quantity' => 1,
							'description' => $productDescription
						);
					} else {
							$cartInfo[] = array (
								'label' => '(' . $productQuantity . ' X ' . $perProductAmount . ') ' . $productName,
								'amount' => (int) strval(round(($currencyConversionFactor * $productAmount), 2) * 100),
								'type' => 'LINE_ITEM'
							);
					}
				}
			}
		}
		
		// Set the TAX amount
		$taxDetails = Frontend::getCart()->gibSteuerpositionen();
		if(!empty($taxDetails)) {
			foreach($taxDetails as $taxDetail) {
				$vatName = $taxDetail->cName;
				$taxAmount += round((($currencyConversionFactor * $taxDetail->fBetrag) * 100));
			}
			if (!$isPaypalcart) {
				$cartInfo[] = array('label' => $vatName, 'amount' => floatval($taxAmount), 'type' => 'SUBTOTAL');
			}
		}
		
		if (!empty($_SESSION['Versandart']) || !empty($_SESSION['AktiveVersandart'])) {
			$shippingMethod = Shop::Container()->getDB()->select('tversandart', 'kVersandart', $_SESSION['Versandart']->kVersandart);
			$shippingName = $shippingMethod->cName;
			
			if(isset($_SESSION['Versandart'])) {
				$shippingName = ($_SESSION['cISOSprache'] == 'ger' && !empty($_SESSION['Versandart']->angezeigterName['ger'])) ? $_SESSION['Versandart']->angezeigterName['ger'] : ($_SESSION['cISOSprache'] == 'eng' && !empty($_SESSION['Versandart']->angezeigterName['eng']) ? $_SESSION['Versandart']->angezeigterName['eng'] : $shippingName);
			}
			$shippingMethodAmount = ($shippingMethod->eSteuer == 'netto') ? ((($taxDetails[0]->fUst/100) * $shippingMethod->fPreis) + $shippingMethod->fPreis) : ($shippingMethod->fPreis);
			$convertedShippingPrice = (int) strval(round(($currencyConversionFactor * $shippingMethodAmount), 2) * 100);
			if ($isPaypalcart) {
				$cartInfo['items_shipping_price'] = $convertedShippingPrice ;
			} else {
				$cartInfo[] = array('label' => $shippingName, 'amount' => $convertedShippingPrice , 'type' => 'SUBTOTAL');
			}
		}
		   
		// Set the coupon information
		$availableCoupons = ['Kupon', 'VersandKupon', 'NeukundenKupon'];
		foreach($availableCoupons as $coupon) {
			if(!empty($_SESSION[$coupon])) {
				$couponName = !empty($_SESSION[$coupon]->translationList) ? ($_SESSION['cISOSprache'] == 'ger' ? $_SESSION[$coupon]->translationList['ger'] : $_SESSION[$coupon]->translationList['eng']) : $_SESSION[$coupon]->cName;
				$couponAmount = (int) strval(round(($currencyConversionFactor * $_SESSION[$coupon]->fWert), 2) * 100);
				if($_SESSION[$coupon]->cWertTyp == 'prozent') {
					$roundedToTwoDecimals = round($totalProductAmount  * ($_SESSION[$coupon]->fWert/100), 2);
					$couponAmount = ($roundedToTwoDecimals - floor($roundedToTwoDecimals) >= 0.5) ? ceil($roundedToTwoDecimals) : floor($roundedToTwoDecimals);								
				}
				if($_SESSION[$coupon] == 'VersandKupon') {
					$couponAmount = (int) strval(round(($currencyConversionFactor * $_SESSION['Versandart']->fEndpreis), 2) * 100);
				}
				$couponAmount *= -1;
				if ($isPaypalcart) {
					$cartInfo['line_items'][] = array ('name' => $couponName, 'price' => $couponAmount, 'quantity' => 1);
				} else {
					$cartInfo[] = array('label' => $couponName, 'amount' => $couponAmount, 'type' => 'SUBTOTAL');
				}
			}
		}
		
		// Set the additional payment fee
		if (!empty($_SESSION['Zahlungsart']) || !empty($_SESSION['AktiveZahlungsart'])) {
			$paymentMethodId = !empty($_SESSION['AktiveZahlungsart']) ? $_SESSION['AktiveZahlungsart'] : $_SESSION['Zahlungsart']->kZahlungsart;
			$paymentMethod = Shop::Container()->getDB()->select('tversandartzahlungsart', ['kVersandart', 'kZahlungsart'], [$_SESSION['Versandart']->kVersandart ,$paymentMethodId]);
			
			if (!empty($paymentMethod->fAufpreis)) {
				if($paymentMethod->cAufpreisTyp == 'prozent') {
					$totalProductAmount = $totalProductAmount + $couponAmount;
					$orderAmount = ($totalProductAmount + $convertedShippingPrice) / 100;
					$additionalPaymentFee = $orderAmount * ($paymentMethod->fAufpreis / 100);
				} else {
					$additionalPaymentFee = $currencyConversionFactor * $paymentMethod->fAufpreis;
				}
				
				if ($isPaypalcart) {
					$cartInfo['items_handling_price'] = (int) strval(round($additionalPaymentFee, 2) * 100);
				} else {
					$cartInfo[] = array('label' => $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_payment_charge'), 'amount' =>  (int) strval(round($additionalPaymentFee, 2) * 100), 'type' => 'SUBTOTAL');
				}
			}
		}
		
		if ($isPaypalcart) {
			return json_encode($cartInfo);
		} else {
			$articleDetails = $cartInfo;
		}
		

        $walletPaymentData = [
                'client_key'    => $this->novalnetPaymentHelper->getConfigurationValues('novalnet_client_key'),
                'test_mode'     => $this->novalnetPaymentHelper->getConfigurationValues($paymentName . '_testmode'),
                'seller_name'   => $this->novalnetPaymentHelper->getConfigurationValues($paymentName . '_seller_name'),
                'button_height' => $this->novalnetPaymentHelper->getConfigurationValues($paymentName . '_button_height'),
                'button_type'   => $this->novalnetPaymentHelper->getConfigurationValues($paymentName . '_button_type'),
                'currency'      => Frontend::getCurrency()->getCode(),
                'payment_type'  => ($paymentName == 'novalnet_googlepay') ? 'GOOGLEPAY' : 'APPLEPAY',
                'amount'        => $this->novalnetPaymentHelper->getOrderAmount(),
                'country_code'  => $this->novalnetPaymentHelper->getConfigurationValues($paymentName . '_country_code'),
                'lang'          => (!empty($_SESSION['cISOSprache']) && $_SESSION['cISOSprache'] == 'eng') ? 'eng' : 'ger',
                'article_details' => $articleDetails
        ];
        return json_encode($walletPaymentData);
    }
    
    /**
	 * Set the Shop based Currency Convert
	 *
	 * @param  object $order
	 * @param  string $amount
	 * @return string
	 */
	public function convertCurrencyFormatter($kBestellung, string $amount) 
	{
		$amount = $amount / 100 ;
		$orderObj  = new Bestellung($kBestellung, true, Shop::Container()->getDB());
		$waehrung = new Currency((int)$orderObj->kWaehrung);
		$totalAmount =  Preise::getLocalizedPriceWithoutFactor($amount, $waehrung, true);
		return $totalAmount;
	}
	
}
