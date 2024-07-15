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
 * Script: NovalnetReturnController.php
 *
*/

namespace Plugin\jtl_novalnet\frontend;

use JTL\Shop;
use JTL\Plugin\PluginInterface;
use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Session\Frontend;
use JTL\Customer\Customer;
use JTLShop\SemVer\Version;
use JTL\Plugin\Payment\Method;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;
use stdClass;

/**
 * Class NovalnetReturnController
 * @package Plugin\jtl_novalnet
 */
class NovalnetReturnController
{
    /**
     * @var object
     */
    private $database;
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;

    /**
     * NovalnetReturnController constructor.
     *
     * @param PluginInterface $plugin
     */
    public function __construct(PluginInterface $plugin)
    {
        $this->plugin = $plugin;
        $this->database = Shop::Container()->getDB();
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();
    }

    /**
     * Handling the payment process in Novalnet server and order creation in the shop after processed the wallet payment
     *
     * @return none
     */
    public function finalProcess(): void
    {
        if(!empty($_GET['tid']) && $_GET['payment_type'] == 'GOOGLEPAY') {
            // Checksum verification and transaction status call to retrieve the full response
            $_SESSION['nn_novalnet_googlepay_payment_response'] = $this->novalnetPaymentGateway->checksumValidateAndPerformTxnStatusCall($_SESSION['nn_order_obj'], $_GET, 'novalnet_googlepay');
            $this->handlePaymentCompletion($_SESSION['nn_order_obj'], 'novalnet_googlepay');
        } else {
            // Set the payment key
            $paymentKey = ($_SESSION['novalnetWalletResponse']['paymentType'] == 'GOOGLEPAY') ?  'novalnet_googlepay' : 'novalnet_applepay';
            // Get payment ID from the core table
            $paymentInfo = Shop::Container()->getDB()->queryPrepared('SELECT kZahlungsart, cModulId FROM tzahlungsart WHERE cModulId LIKE :paymentType', [':paymentType' => '%' . strtolower($_SESSION['novalnetWalletResponse']['paymentType'])  ], 2);
            foreach($paymentInfo as $payment) {
                // Set the payment in session
                $paymentMethod = new stdClass();
                $paymentMethod->kZahlungsart = $payment->kZahlungsart;
                $paymentMethod->cModulId = $payment->cModulId;
                if($paymentKey == 'novalnet_googlepay') {
                    $paymentMethod->angezeigterName = ['ger' => 'Google Pay', 'eng' => 'Google Pay'];
                } else {
                    $paymentMethod->angezeigterName = ['ger' => 'Apple Pay', 'eng' => 'Apple Pay'];
                }
                $_SESSION['Zahlungsart'] = $paymentMethod;
            }
            require_once PFAD_ROOT . PFAD_INCLUDES . 'bestellabschluss_inc.php';
            $shopVersion = Version::parse(APPLICATION_VERSION)->getOriginalVersion();
            // Used the shop core function for creating the order based the lower and Higher version
            if($shopVersion >= '5.2.0') {
                $orderHandler  = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
                // Used for the shipping address insertion process
                $_SESSION['Bestellung'] = new stdClass();
                $_SESSION['Bestellung']->kLieferadresse = -1;
                $order = $orderHandler->finalizeOrder();
            } else {
                $order = finalisiereBestellung();
            }
            // Build the required parameters
            $paymentRequestData = $this->novalnetPaymentGateway->generatePaymentParams($order, $paymentKey);
            $paymentRequestData['transaction']['amount'] =  $this->novalnetPaymentGateway->novalnetPaymentHelper->getOrderAmount($order);
            $paymentRequestData['transaction']['payment_type'] = $_SESSION['novalnetWalletResponse']['paymentType'];
            $paymentRequestData['transaction']['payment_data'] = [
                                                                        'wallet_token'   => $_SESSION['novalnetWalletResponse']['transaction']['token']
                                                                     ];
            // Handle the authentication process
            if(!empty($_SESSION['novalnetWalletResponse']['transaction']['doRedirect']) && $_SESSION['novalnetWalletResponse']['paymentType'] == 'GOOGLEPAY') {
                $paymentRequestData['transaction']['return_url']   = Shop::getURL().'/novalnetwallet-return-'.$_SESSION['cISOSprache'];
            }
            $paymentRequestData['payment_url'] = !empty($this->novalnetPaymentGateway->isTransactionRequiresAuthorizationOnly($paymentRequestData['transaction']['amount'], $paymentKey)) ? 'authorize' : 'payment';
            
            $_SESSION['nn_'. $paymentKey. '_request'] = $paymentRequestData;
            
            $_SESSION['nn_'. $paymentKey.'_payment_response'] = $this->novalnetPaymentGateway->performServerCall($_SESSION['nn_'. $paymentKey. '_request'], $paymentRequestData['payment_url']);
            
            // Do redirect if the redirect URL is present
            if (!empty($_SESSION['nn_'. $paymentKey.'_payment_response']['result']['redirect_url']) && !empty($_SESSION['nn_'. $paymentKey.'_payment_response']['transaction']['txn_secret'])) {  

                // Transaction secret used for the later checksum verification
                $_SESSION[$paymentKey]['novalnet_txn_secret'] = $_SESSION['nn_'. $paymentKey.'_payment_response']['transaction']['txn_secret'];
                // Set the order object into session for the further redirect process
                $_SESSION['nn_order_obj'] = $order;
                
                \header('Location: ' . $_SESSION['nn_'. $paymentKey.'_payment_response']['result']['redirect_url']);
                exit;
            }
            $this->handlePaymentCompletion($order, $paymentKey);
        }
        
    }
    
    /**
     * Handling the payment completion process
     *
     * @param object $order
     * @param string $paymentKey
     * @return none
     */
    public function handlePaymentCompletion($order, $paymentKey): void
    {
        // Create the payment method ID
        $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
        
        $isTransactionPaid = '';
        
        // Add the incoming payments if the transaction was confirmed
        if ($_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['status'] == 'CONFIRMED') {
            $incomingPayment           = new stdClass();
            $incomingPayment->fBetrag  = $order->fGesamtsummeKundenwaehrung;
            $incomingPayment->cISO     = $order->Waehrung->cISO;
            $incomingPayment->cHinweis = $_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['tid'];
            // Add the current transaction payment into db
            $jtlPaymentmethod->addIncomingPayment($order, $incomingPayment);  
            
            // Update the payment paid time to the shop order table
            $isTransactionPaid = true;
        }
        
        // Updates transaction ID into shop for reference
        $jtlPaymentmethod->updateNotificationID($order->kBestellung, (string) $_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['tid']); 
        
        // Getting the transaction comments to store it in the order 
        $transactionDetails = $this->novalnetPaymentGateway->getTransactionInformation($order, $_SESSION['nn_'.$paymentKey.'_payment_response'], $paymentKey);

        if (in_array($_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) {
            $transactionDetails .=  \PHP_EOL . sprintf($this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_wallet_text'), $_SESSION['Zahlungsart']->angezeigterName['ger'], $_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['payment_data']['card_brand'], $_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['payment_data']['last_four']);
        }
        
        // Setting up the Order Comments and Order status in the order table 
        $orderStatus = (($_SESSION['nn_'.$paymentKey.'_payment_response']['transaction']['status'] == 'ON_HOLD') ? constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_onhold_order_status')) : constant($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('order_completion_status', $paymentKey)));
        
        $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'cBestellNr', $order->cBestellNr, ['cStatus' => $orderStatus, 'cKommentar' =>  $transactionDetails, 'dBezahltDatum' => ($isTransactionPaid ? 'NOW()' : '')]); 
        
        // Completing the order based on the resultant status 
        $this->novalnetPaymentGateway->handlePaymentCompletion($order, $paymentKey, $jtlPaymentmethod->generateHash($order));
    }
}
