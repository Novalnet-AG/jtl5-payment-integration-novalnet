<?php
/**
 * This file is act as helper for the Novalnet payment plugin
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: NovalnetPaymentGateway.php
 *
*/

namespace Plugin\jtl_novalnet\paymentmethod;

use JTL\Shop;
use Plugin\jtl_novalnet\src\NovalnetPaymentHelper;
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
     * @var NovalnetPaymentHelper
     */
    public $novalnetPaymentHelper;

    /**
     * NovalnetPaymentGateway constructor.
     */
    public function __construct()
    {
       $this->novalnetPaymentHelper = new NovalnetPaymentHelper();
    }

    /**
     * Checks the required payment activation configurations
     *
     * return bool
     */
    public function canPaymentMethodProcessed(): bool
    {
        return ($this->novalnetPaymentHelper->getConfigurationValues('novalnet_enable_payment_method') && ($this->novalnetPaymentHelper->getConfigurationValues('novalnet_public_key') != '' && $this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key') != '' && $this->novalnetPaymentHelper->getConfigurationValues('novalnet_tariffid') != ''));
    }

    /**
     * Build payment parameters to server
     *
     * @param  object|null $order
     * @return array
     */
    public function generatePaymentParams(?object $order = null, $theme = false): array
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
        $billingShippingDetails = $this->novalnetPaymentHelper->getRequiredBillingShippingDetails($customerDetails);

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
                                               'amount'    => $this->novalnetPaymentHelper->getOrderAmount($order),
                                               'currency'  => Frontend::getCurrency()->getCode(),
                                               'system_name'   => 'jtlshop',
                                               'system_version' => $this->getSytemVersion($theme),
                                               'system_url' => Shop::getURL(),
                                               'system_ip'  => $this->novalnetPaymentHelper->getNnIpAddress('SERVER_ADDR')
                                             ];

        // If the order generation is done before the payment completion, we get the order number in the initial call itself
        if (isset($_SESSION['Zahlungsart']->nWaehrendBestellung) && $_SESSION['Zahlungsart']->nWaehrendBestellung == 0 && isset($order->cBestellNr)) {
            $paymentRequestData['transaction']['order_no'] = $order->cBestellNr;
        }
        // Send the order language
        $paymentRequestData['custom']['lang'] = (!empty($_SESSION['cISOSprache']) && $_SESSION['cISOSprache'] == 'ger') ? 'DE' : 'EN';

        // Unset the shipping address if the billing and shipping address are equal
        if (!empty($paymentRequestData['customer']['shipping']['same_as_billing'])) {
            unset($paymentRequestData['customer']['shipping']);
            $paymentRequestData['customer']['shipping']['same_as_billing'] = 1;
        }

        return $paymentRequestData;
    }

    /**
     * Returns with error message on failure cases
     *
     * @param  object  $order
     * @param  array   $paymentResponse
     * @param  string  $paymentName
     * @param  string  $explicitErrorMessage
     * @return none
     */
    public function redirectOnError(object $order, array $paymentResponse, string $paymentName, string $explicitErrorMessage = ''): void
    {
        // Set the error message from the payment response
        $errorMsg = (!empty($paymentResponse['result']['status_text']) ? $paymentResponse['result']['status_text'] : $paymentResponse['status_text']);

        // If the order has been created already and if the order has to be closed
        if ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {

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
            unset($_SESSION['novalnet']); // unset novalnet session

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
        \header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
        exit;
    }

    /**
     * Make CURL payment request to Novalnet server
     *
     * @param  array $paymentRequestData
     * @param  string $paymentUrl
     * @param  string $paymentAccessKey
     * @return array
     */
    public function performServerCall(array $paymentRequestData, string $paymentUrl, string $paymentAccessKey = '')
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
     * @param  string $apiType
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
     * Validates the novalnet payment response
     *
     * @param  object    $order
     * @param  array     $paymentResponse
     * @param  string    $paymentName
     * @return none|bool
     */
    public function validatePaymentResponse(object $order, array $paymentResponse, string $paymentName): ?bool
    {
        // Building the failure transaction comments
        $transactionComments = $this->getTransactionInformation($order, $paymentResponse, $paymentName);

        // Routing if the result is a failure
        if (!empty($paymentResponse['result']['status']) && $paymentResponse['result']['status'] != 'SUCCESS') {

            if ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {
                // Logs the order details in Novalnet tables for failure
                $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $order->kBestellung, ['cKommentar' => $transactionComments]);
            }
            $this->redirectOnError($order, $paymentResponse, $paymentName);

        } else {
			# Set the Novalnet transaction comments into session. It require for assign the comment into order object
            $transactionDetails = $this->getTransactionInformation($order, $paymentResponse, $paymentName);
            $_SESSION['novalnet']['comments'] = $transactionComments;
            return true;
        }
    }

    /**
     * Process while handling handle_notification URL
     *
     * @param  object  $order
     * @param  string $paymentName
     * @param  string $sessionHash
     * @return none
     */
    public function handlePaymentCompletion(object $order, string $paymentName, string $sessionHash, string $transactionDetails = null): void
    {
        $paymentResponse = $_SESSION['novalnet']['payment_response'];
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
                        $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung',  $order->kBestellung, ['cKommentar' => $transactionDetails]);
                    }
                }
				$txAdditonalInfo['cKommentar'] = $transactionDetails;
                // Inserting order details into Novalnet table 
                $this->insertOrderDetailsIntoNnDb($order, $paymentResponse, $paymentName, $txAdditonalInfo); 
                $updateWawi = 'Y';

                // Update the WAWI pickup status as 'Nein' for confirmed transaction
                if ($paymentResponse['transaction']['status'] == 'CONFIRMED' || (in_array($paymentResponse['transaction']['payment_type'], ['INVOICE', 'PREPAYMENT', 'CASHPAYMENT', 'MULTIBANCO']) && $paymentResponse['transaction']['status'] == 'PENDING')) {
                    $updateWawi = 'N';
                }

                // Updates the value into the database
                $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung',  $order->kBestellung, ['cAbgeholt' => $updateWawi]);

                // Unset the entire novalnet session on order completion
                unset($_SESSION['novalnet']);

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

        $transactionComments .= !empty($_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['name']) ? $_SESSION['novalnet']['seamless_payment_form_response']['payment_details']['name'] : $order->cZahlungsartName;

        // Set the Novalnet transaction id based on the response
        $novalnetTxTid = !empty($paymentResponse['transaction']['tid']) ? $paymentResponse['transaction']['tid'] : $paymentResponse['tid'];

        if (!empty($novalnetTxTid)) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_transaction_tid') . ': '. $novalnetTxTid;
        }

        // Set the Novalnet transaction mode based on the response
        $novalnetTxMode = !empty($paymentResponse['transaction']['test_mode']) ? $paymentResponse['transaction']['test_mode'] : '';

        if (!empty($novalnetTxMode)) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_test_order');
        }

        if ($paymentResponse['transaction']['status'] == 'CONFIRMED' && $paymentResponse['transaction']['amount'] == 0) {
			$transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_zero_amount_booking_text');
		}

        if (strpos($paymentName, 'guaranteed') !== false) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guarantee_text');
        }

        if (in_array($paymentName, ['guaranteed_invoice', 'instalment_invoice'])  && $paymentResponse['transaction']['status'] == 'PENDING') {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guaranteed_invoice_pending_text');
        }

        if (in_array($paymentName, ['guaranteed_direct_debit_sepa', 'instalment_direct_debit_sepa']) && $paymentResponse['transaction']['status'] == 'PENDING') {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guaranteed_sepa_pending_text');
        }

        // Collecting the bank details required for the Invoice payment methods
        if ((in_array($paymentName, array('instalment_invoice', 'guaranteed_invoice')) && in_array($paymentResponse['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) ||  in_array($paymentName, array('invoice', 'prepayment'))) {
            $transactionComments .= $this->getBankdetailsInformation($order, $paymentResponse);
        }

        // Collecting the store details
        if (!empty($paymentResponse['transaction']['nearest_stores'])) {
            $transactionComments .= $this->getStoreInformation($paymentResponse);
        }

        // Collecting the Multibanco reference details
        if (!empty($paymentResponse['transaction']['partner_payment_reference'])) {
            $transactionComments .= $this->getMultibancoReferenceInformation($order, $paymentResponse);
        }

        // Display the wallet card details
        if (in_array($paymentName, array('googlepay', 'applepay')) && in_array($paymentResponse['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) {
            $transactionComments .=  \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_wallet_text'), $order->cZahlungsartName, $paymentResponse['transaction']['payment_data']['card_brand'], $paymentResponse['transaction']['payment_data']['last_four']);
        }

        return $transactionComments;
    }

    /**
     * Setting up the Invoice bank details for storing in the order
     *
     * @param  object      $order
     * @param  array       $paymentResponse
     * @param  string|null $lang
     * @return string
     */
    public function getBankdetailsInformation(object $order, array $paymentResponse, ?string $lang = null): string
    {
        $amount = (!empty($paymentResponse['instalment']['cycle_amount'])) ? $paymentResponse['instalment']['cycle_amount'] : $paymentResponse['transaction']['amount'];
		
		if(!empty($order->kBestellung)) {
			$updateAmount = $this->convertCurrencyFormatter($order->kBestellung, $amount);
			if ($paymentResponse['transaction']['status'] != 'ON_HOLD' && isset($paymentResponse['transaction']['due_date'])) {
				$invoiceComments = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payment_transfer_duedate_comments', $lang), $updateAmount, $paymentResponse['transaction']['due_date']);
			} else {
				$invoiceComments = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payment_transfer_comment', $lang), $updateAmount);
			}
		}
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_holder', $lang) . $paymentResponse['transaction']['bank_details']['account_holder'];
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_iban', $lang) . $paymentResponse['transaction']['bank_details']['iban'];
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_bic', $lang) . $paymentResponse['transaction']['bank_details']['bic'];
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_bank', $lang) . $paymentResponse['transaction']['bank_details']['bank_name'] . ' ' . $paymentResponse['transaction']['bank_details']['bank_place'];

        // Adding the payment reference details
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_single_reference_text', $lang);
        $firstPaymentReference = (!empty($paymentResponse['instalment']['cycle_amount'])  && empty($paymentResponse['transaction']['invoice_ref'])) ? 'jtl_novalnet_instalment_payment_reference' : 'jtl_novalnet_invoice_payments_first_reference';
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation($firstPaymentReference, $lang) . $paymentResponse['transaction']['tid'];
        if (!empty($paymentResponse['transaction']['invoice_ref'])) {
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_second_reference', $lang) . $paymentResponse['transaction']['invoice_ref'];
        }
        return $invoiceComments;
    }

    /**
     * Setting up the Cashpayment store details for storing in the order
     *
     * @param  array $paymentResponse
     * @return string
     */
    public function getStoreInformation(array $paymentResponse): string
    {
        $cashpaymentComments  = \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_expiry_date') . $paymentResponse['transaction']['due_date'] . \PHP_EOL;
        $cashpaymentComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_nearest_store_details') . \PHP_EOL;

        // There would be a maximum of three nearest stores for the billing address
        $nearestStores = $paymentResponse['transaction']['nearest_stores'];

        // We loop in each of them to print those store details
        for ($storePos = 1; $storePos <= count($nearestStores); $storePos++) {
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['store_name'];
            $cashpaymentComments .= \PHP_EOL . mb_convert_encoding($nearestStores[$storePos]['street'], 'UTF-8', mb_detect_encoding($nearestStores[$storePos]['street']));
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['city'];
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['zip'];
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['country_code'];
            $cashpaymentComments .= \PHP_EOL;
        }

        return $cashpaymentComments;
    }

     /**
     * Setting up the Multibanco reference details for storing in the order
     *
     * @param object $order
     * @param array $paymentResponse
     * @return string
     */
    public function getMultibancoReferenceInformation(object $order, array $paymentResponse): string
    {
		$multibancoAmount = $this->convertCurrencyFormatter($order->kBestellung, ($paymentResponse['transaction']['amount']));
		
        $multibancoComments  = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_multibanco_reference_text'), $multibancoAmount);
        $multibancoComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_multibanco_reference_one') . $paymentResponse['transaction']['partner_payment_reference'];
        $multibancoComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_multibanco_reference_two') . $paymentResponse['transaction']['service_supplier_id'];

        return $multibancoComments;
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
        
        if((isset($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action']) && $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action'] == 'zero_amount') ||  ($paymentResponse['transaction']['status'] == 'CONFIRMED' && $paymentResponse['transaction']['amount'] == 0)) {
			$zero_amount_booking = array ('zero_amount' => 1);
		}
        $insertOrder = new stdClass();
        $insertOrder->cNnorderid         = $order->cBestellNr;
        $insertOrder->nNntid             = !empty($paymentResponse['transaction']['tid']) ? $paymentResponse['transaction']['tid'] : $paymentResponse['tid'];
        $insertOrder->cZahlungsmethode   = $paymentName;
        $insertOrder->cMail              = $customerDetails->cMail;
        $insertOrder->cStatuswert        = !empty($paymentResponse['transaction']['status']) ? $paymentResponse['transaction']['status'] : $paymentResponse['status'];
        $insertOrder->nBetrag            = !empty($paymentResponse['instalment']['total_amount']) ? $paymentResponse['instalment']['total_amount'] : (!empty($paymentResponse['transaction']['amount']) ? $paymentResponse['transaction']['amount'] : (round($order->fGesamtsumme) * 100));
        $insertOrder->cAdditionalInfo    = json_encode(array_merge((array) $paymentResponse['instalment'], (array) $txAdditonalInfo));
        $insertOrder->nCallbackAmount    = !in_array($paymentName, ['invoice', 'prepayment', 'cashpayment', 'multibanco']) ? $paymentResponse['transaction']['amount'] : 0;

        Shop::Container()->getDB()->insert('xplugin_novalnet_transaction_details', $insertOrder);
    }

    /**
     * Complete the order
     *
     * @param  object $order
     * @param  string $paymentName
     * @param  string $sessionHash
     * @return none
     */
    public function completeOrder(object $order, string $paymentName, string $sessionHash): void
    {
        $paymentResponse = $_SESSION['novalnet']['payment_response'];

        if ($paymentResponse) {
            // If the order is already complete, we do the appropriate action
            if ($paymentResponse['result']['status'] == 'SUCCESS') {

                // Unset the entire novalnet session on order completion
                unset($_SESSION['novalnet']);

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
    public function checksumValidateAndPerformTxnStatusCall(object $order, array $paymentResponse, string $paymentName): ?array
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
     * @param  mixed       $orderNo
     * @param  string|null $lang
     * @return array|null
     */
    public function getInstalmentInfoFromDb($orderNo, ?string $lang = null, $orderId=null): ?array
    {
        $transactionDetails = Shop::Container()->getDB()->queryPrepared('SELECT nov.nNntid, nov.cStatuswert, nov.nBetrag, nov.cAdditionalInfo  FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov ON ord.cBestellNr = nov.cNnorderid WHERE cNnorderid = :cNnorderid and nov.cZahlungsmethode LIKE :instalment', [':cNnorderid' => $orderNo, ':instalment' => '%instalment%'], 1);

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
                $instalmentInfo['insDetails'][$instalment]['cycle_amount'] = $instalmentAmount ;
                } else {
                    $cycleAmount = ($transactionDetails->nBetrag - ($insAdditionalInfo['cycle_amount'] * ($instalment - 1)));
                    $instalmentAmount = $this->convertCurrencyFormatter($orderId, $cycleAmount);
                    $instalmentInfo['insDetails'][$instalment]['cycle_amount'] = $instalmentAmount;
                }
                $instalmentInfo['insDetails'][$instalment]['tid'] = !empty($insAdditionalInfo[$instalment]['tid']) ?  $insAdditionalInfo[$instalment]['tid'] : '-';
                $instalmentInfo['insDetails'][$instalment]['payment_status'] = ($instalment_cycle_cancel == 'all_cycles') ? Shop::Lang()->get('statusCancelled', 'order') : (($instalmentInfo['insDetails'][$instalment]['tid'] != '-') ? Shop::Lang()->get('statusPaid', 'order') : ($instalment_cycle_cancel == 'remaining_cycles' ? Shop::Lang()->get('statusCancelled', 'order') : Shop::Lang()->get('statusPending', 'order')));
                if(($instalment) != ($totalInstalments)) {
					$instalmentInfo['insDetails'][$instalment]['future_instalment_date'] = date("d.m.Y", strtotime($instalmentCycle[$instalment + 1]));
				}
            }

            $instalmentInfo['lang'] = $this->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_instalment_information', 'jtl_novalnet_serial_no', 'jtl_novalnet_instalment_future_date', 'jtl_novalnet_instalment_amount', 'jtl_novalnet_instalment_transaction_id', 'jtl_novalnet_order_status'], $lang);
             $instalmentInfo['status'] = $transactionDetails->cStatuswert;

            return $instalmentInfo;
        }

        return null;
    }
    
    /**
     * Get the mandatory paramters for the payments
     *
     * @param  array $paymentRequestData
     * @return none
     */
    public function getMandatoryPaymentParameters(array &$paymentRequestData): void
    {
        // If the consumer has opted to pay with the saved account or card data, we use the token relavant to that
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_ref_token'])) {
            // Selected token is the key to the stored payment data
            $paymentRequestData['transaction']['payment_data']['token'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_ref_token'];
        } else {
            // If the consumer has opted to save the account or card data for future purchases, we notify the server
            if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['create_token'])) {
                $paymentRequestData['transaction']['create_token'] = 1;
            }
            // For Credit card payment
            if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['pan_hash'])) {
                // Setting up the alternative card data to the server for card processing
                $paymentRequestData['transaction']['payment_data'] = [
                                                                        'pan_hash'   => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['pan_hash'],
                                                                        'unique_id'  => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['unique_id']
                                                                     ];

                // If the enforced 3D option is enabled, we notify the server about the forced 3D handling
                if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['do_redirect'])) {
                    $paymentRequestData['transaction']['payment_data']['enforce_3d'] = 1;
                }
            }
            // For Direct debit SEPA payment
            if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['iban'])) {
                // Setting up the account data to the server for SEPA processing
                $paymentRequestData['transaction']['payment_data'] = [
                                                                        'iban'       => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['iban']
                                                                     ];
                if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['bic'])) {
                    $paymentRequestData['transaction']['payment_data']['bic'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['bic'];
                }
            }
        }
        // Notify the server about period of instalment
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['cycle'])) {
            $paymentRequestData['instalment']['cycles'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['cycle'];
        }
        // Send the Birthday to server for the Guaranteed payments
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['birth_date'])) {
            $paymentRequestData['customer']['birth_date'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['birth_date'];
            unset($paymentRequestData['customer']['billing']['company']);
        }
        // Send the wallet token to the server
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['wallet_token'])) {
            // Setting up the account data to the server for SEPA processing
            $paymentRequestData['transaction']['payment_data'] = [
                                                                    'wallet_token'       => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['wallet_token']
                                                                 ];
        }
        // Send the due date tothe server
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['due_date'])) {
            $configuredDueDate = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['due_date'];
            $paymentRequestData['transaction']['due_date'] = date('Y-m-d', strtotime('+' . $configuredDueDate . 'days'));
        }
        // Process the zero amount booking
        if(isset($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action']) && $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['payment_action'] == 'zero_amount') {
            $paymentRequestData['transaction']['amount'] = 0;
        }
        // Process the Direct Debit ACH
        $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_holder'] = !empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_holder']) ? $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_holder'] : $paymentRequestData['customer']['first_name'] . ' ' . $paymentRequestData['customer']['last_name'];

        if (!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_holder']) && !empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_number']) && !empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['routing_number'])) {
			$paymentRequestData['transaction']['payment_data'] = [
																		'account_holder' => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_holder'],
                                                                        'account_number' => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['account_number'],
                                                                        'routing_number' => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['routing_number']
                                                                  ];
		}
		// Process the MB Way
		$_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['mobile'] = !empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['mobile']) ? $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['mobile'] : (!empty($paymentRequestData['customer']['mobile']) ? $paymentRequestData['customer']['mobile'] : '');
		if (!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['mobile'])) {
			$paymentRequestData['customer']['mobile'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['mobile'];
		}
    }

    /**
     * Form the cart details
     *
     * @param bool $isPaypalcart
     * @return string|array
     */
	public function getBasketDetails($isPaypalcart = false)
	{
		// Get article details
		$cartDetails = Frontend::getCart();
		$currencyConversionFactor = Frontend::getCurrency()->getConversionFactor();
		$taxAmount = $vatName = $totalProductAmount = $totalOrderAmount = $convertedShippingPrice = $couponAmount = 0;
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

		// Set the Credit amount
		if(!empty($_SESSION['Bestellung']->GutscheinLocalized)) {
			$creditAmount 				= $_SESSION['Bestellung']->GutscheinLocalized;
			$creditAmount 				= preg_replace('/[^0-9,.]/', '', $creditAmount);
			$creditAmount 				= (float) str_replace(',', '.', $creditAmount);
			$convertedCreditAmount 		=  strval(round($creditAmount, 2) * 100);
			if ($isPaypalcart) {
				$cartInfo['line_items'][] = array('name' => $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_credit_used'), 'price' => -( $convertedCreditAmount ), 'quantity' => 1);
			} else {
				$cartInfo[] = array('label' => $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_credit_used'), 'amount' => -( $convertedCreditAmount ), 'type' => 'SUBTOTAL');
			}
		}
		
		// Set the shipping method
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
						$couponAmount = (int) strval(round($totalProductAmount * ($_SESSION[$coupon]->fWert/100), 2));
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
			return $cartInfo;
		} else {
			foreach ($cartInfo as $cart) {
				$totalOrderAmount += $cart['amount'];
			}
			$_SESSION['novalnet']['order_amount'] = (int) strval($totalOrderAmount - $taxAmount); // Reduce the tax amount because already added in product price
			return json_encode($cartInfo);
		}
	}

    /**
	 * Set the System Version and Theme
	 *
	 * @param  bool $themeAvailable
	 * @return string
	 */
	public function getSytemVersion($themeAvailable = false) {
		$theme =  Version::parse(APPLICATION_VERSION)->getOriginalVersion();
		if($themeAvailable) {
			// Selected theme in the shop
			$themeName = ucfirst(Shop::getSettings([CONF_TEMPLATE])['template']['theme']['theme_default']);
			$theme .= '-NN13.1.3-NNTjtlshop_'.$themeName;
		}
		return $theme;
	}
	
	/**
	 * Set the Shop based Currency Convert
	 *
	 * @param  object $order
	 * @param  int $amount
	 * @return int
	 */
	public function convertCurrencyFormatter($kBestellung, string $amount) {
		$amount = $amount / 100;
		$orderObj  = new Bestellung($kBestellung, true, Shop::Container()->getDB());
		$waehrung = new Currency((int)$orderObj->kWaehrung);
		$totalAmount =  Preise::getLocalizedPriceWithoutFactor($amount, $waehrung, true);
		return $totalAmount;
	}
}
