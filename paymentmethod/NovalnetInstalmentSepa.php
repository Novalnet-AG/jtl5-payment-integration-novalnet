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
 * Script: NovalnetInstalmentSepa.php
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
use JTL\Catalog\Product\Preise;
use JTL\Cart\CartHelper;
use stdClass;

/**
 * Class NovalnetInstalmentSepa
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetInstalmentSepa extends Method
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
    private $paymentName = 'novalnet_instalment_sepa';
    
    /**
     * NovalnetInstalmentSepa constructor.
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

        $this->name    = 'Novalnet Ratenzahlung per SEPA-Lastschrift';
        $this->caption = 'Novalnet Ratenzahlung per SEPA-Lastschrift';

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
        return ($this->novalnetPaymentGateway->canPaymentMethodProcessed($this->paymentName) && $this->novalnetPaymentGateway->isGuaranteedPaymentAllowed($this->paymentName));
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
        
        $company = '';
        
        // Only if the B2B option is enabled, we process the company data
        if ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('allow_b2b_customer', $this->paymentName)) {
            $company = !empty(Frontend::getCustomer()->cFirma) ? Frontend::getCustomer()->cFirma : $_SESSION['Kunde']->cFirma;          
        }
        
        // If the customer has placed a succesful order already and have opted for quick checkout, then previous account details are restored
        $accountDetails = '';
        if ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('one_click_shopping', $this->paymentName) && !empty(Frontend::getCustomer()->kKunde)) {
            $proceedOneclickShopping = 1;
            $accountDetails = $this->novalnetPaymentGateway->novalnetPaymentHelper->getPaymentReferenceValues('sepa');
        }
            
        // Instalment cycle amount information for the payment methods 
        $instalmentCyclesAmount = [];
        foreach ($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('cycles', $this->paymentName) as $cycle) {
            $cycleAmount = ($_SESSION['Warenkorb']->gibGesamtsummeWaren(true) / $cycle ) * 100;
            // Assign the cycle amount if th cycle amount greater than
            if ($cycleAmount > 999) {
                $instalmentCyclesAmount[$cycle] = number_format(($_SESSION['Warenkorb']->gibGesamtsummeWaren(true) / $cycle ), 2, ',', '');
            }
        }              
        
        $languageTexts = $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(array('jtl_novalnet_sepa_account_number', 'jtl_novalnet_save_account_data','jtl_novalnet_add_new_account_details', 'jtl_novalnet_remove_account_detail', 'jtl_novalnet_account_detail_removed', 'jtl_novalnet_account_detail_invalid', 'jtl_novalnet_sepa_mandate_text', 'jtl_novalnet_sepa_mandate_instruction_one', 'jtl_novalnet_sepa_mandate_instruction_two', 'jtl_novalnet_sepa_mandate_instruction_three', 'jtl_novalnet_dob', 'jtl_novalnet_birthdate_error', 'jtl_novalnet_age_limit_error', 'jtl_novalnet_instalment_plan', 'jtl_novalnet_net_amount', 'jtl_novalnet_month', 'jtl_novalnet_instalment_amount', 'jtl_novalnet_instalment_cycles', 'jtl_novalnet_instalment_per_month_text'));
                       
        // Handle additional data is called only when the Customer does not have a company field                        
        Shop::Smarty()->assign('pluginPath', $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getPaths()->getBaseURL())
                      ->assign('languageTexts', $languageTexts)                      
                      ->assign('netAmount', Preise::getLocalizedPriceWithoutFactor(Frontend::getCart()->gibGesamtsummeWaren(true)))                       
                      ->assign('instalmentCyclesAmount', $instalmentCyclesAmount)
                      ->assign('orderAmount', $this->novalnetPaymentGateway->novalnetPaymentHelper->getOrderAmount())
                      ->assign('currency', Frontend::getCurrency()->getHtmlEntity())    
                      ->assign('accountDetails', $accountDetails)                         
                      ->assign('paymentName', $this->paymentName)                         
                      ->assign('oneClickShoppingEnabled', $proceedOneclickShopping)   ->assign('company', $company);                          
                                  
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
        $paymentRequestData['transaction']['payment_type'] = 'INSTALMENT_DIRECT_DEBIT_SEPA';
        $paymentRequestData['instalment']['cycles']        = $_SESSION[$this->paymentName]['nn_instalment_cycle'];
        
        // Extracting the SEPA due date information based on the configuration
        $sepaDueDate = $this->novalnetPaymentGateway->getSepaDuedate($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_instalment_sepa_due_date'));
        
        $paymentRequestData['transaction']['due_date'] = $sepaDueDate;
        
        // If the consumer has opted to pay with the saved account data, we use the token relavant to that      
        if (!empty($_SESSION[$this->paymentName]['nn_customer_selected_token'])) {
            // Selected token is the key to the stored payment data             
            $paymentRequestData['transaction']['payment_data']['token'] = $_SESSION[$this->paymentName]['nn_customer_selected_token'];      
        } else {        
            // If the consumer has opted to save the account data for future purchases, we notify the server
            if (!empty($_SESSION[$this->paymentName]['nn_save_payment_data'])) {
                $paymentRequestData['transaction']['create_token'] = 1;
            }
            
            // Setting up the account data to the server for SEPA processing
            $paymentRequestData['transaction']['payment_data'] = [
                                                                    'account_holder' => Frontend::getCustomer()->cVorname . ' ' . Frontend::getCustomer()->cNachname,
                                                                    'iban'           => $_SESSION[$this->paymentName]['nn_sepa_account_no']
                                                                 ];
        }
        
        // If the date of birth is set, we find it as B2C consumer and unsets the company data 
        if (!empty($_SESSION[$this->paymentName]['nn_dob'])) {
            
            if (!empty($paymentRequestData['customer']['billing']['company'])) {
                unset($paymentRequestData['customer']['billing']['company']);
            }
            
            $paymentRequestData['customer']['birth_date'] = $_SESSION[$this->paymentName]['nn_dob'];
        }       
        
        // Checking if the payment type has authorization is in place or immediate capture 
        $paymentRequestData['payment_url'] = !empty($this->novalnetPaymentGateway->isTransactionRequiresAuthorizationOnly($paymentRequestData['transaction']['amount'], $this->paymentName)) ? 'authorize' : 'payment';
        
        $_SESSION['nn_'. $this->paymentName . '_request'] = $paymentRequestData;
        
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
        $_SESSION['nn_'. $this->paymentName .'_payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['nn_'. $this->paymentName . '_request'], $_SESSION['nn_'. $this->paymentName . '_request']['payment_url']);
        
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
        
        // Completing the order based on the resultant status 
        $this->novalnetPaymentGateway->handlePaymentCompletion($order, $this->paymentName, $this->generateHash($order));
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
