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
 * Script: NovalnetHookHandler.php
 *
*/
 
namespace Plugin\jtl_novalnet\frontend;

use JTL\Shop;
use JTL\Checkout\Bestellung;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;

/**
 * Class NovalnetHookHandler
 * @package Plugin\jtl_novalnet
 */
class NovalnetHookHandler
{
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;
    
    
    public function __construct()
    {
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();  
    }
    
    /**
     * Display the Novalnet transaction comments on order status page when payment before order completion option is set to 'Ja'
     *
     * @param  array  $args
     * @return none
     */
    public function orderStatusPage(array $args): void
    {
        if (strpos($_SESSION['Zahlungsart']->cModulId, 'novalnet') !== false && !empty($_SESSION['nn_comments'])) {
            $args['oBestellung']->cKommentar = $_SESSION['nn_comments'];
        }
    }
    
    /**
     * Display the Novalnet transaction comments aligned in My Account page of the user
     *
     * @return none
     */
    public function accountPage(): void
    {
        if (!empty(Shop::Smarty()->tpl_vars['Bestellung'])) {            
            Shop::Smarty()->tpl_vars['Bestellung']->value->cKommentar = nl2br(Shop::Smarty()->tpl_vars['Bestellung']->value->cKommentar); 
            $lang = (Shop::Smarty()->tpl_vars['Bestellung']->value->kSprache == 1) ? 'ger' : 'eng'; 
            
            $orderNo = Shop::Smarty()->tpl_vars['Bestellung']->value->cBestellNr;
            $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($orderNo, $lang);
            Shop::Smarty()->assign('instalmentDetails', $instalmentInfo);    
        }  
    }
    
    /**
     * Used for the frontend template customization for the Credit Card Logo and Barzahlen slip 
     *
     * @param  array $args
     * @return none
     */
    public function contentUpdate(array $args): void
    {
        $smarty = Shop::Smarty();
        
        if (Shop::getPageType() === \PAGE_BESTELLVORGANG) {
            $this->displayNnCcLogoOnPaymentPage($smarty);
        } elseif(Shop::getPageType() == \PAGE_BESTELLABSCHLUSS) {
            $this->displayNnCashpaymentSlip($smarty);
        }
    }

    /**
     * Displays the Novalnet Credit Card logo on payment page based on the configuration
     *
     * @param  object  $smarty
     * @return none
     */
    public function displayNnCcLogoOnPaymentPage(object $smarty): void
    {
        global $step;
        
        if (in_array($step, ['Zahlung', 'Versand'])) {

        
        $pluginPath = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getPaths()->getBaseURL();
        $testmodeLang = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_test_mode_text');
        
        $getNnConfigurations = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getConfig()->getOptions();
             
        foreach($getNnConfigurations as $configuration ) {
            if (strpos($configuration->valueID, trim('testmode') ) && $configuration->valueID != 'novalnet_webhook_testmode') {
                $novalnetTestmode = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getConfig()->getValue($configuration->valueID);
                if (!empty($novalnetTestmode)) {
                    $paymentTestmodeArrs = [];
                    $paymentTestmodeArrs[$configuration->valueID] = $novalnetTestmode;
                    foreach($paymentTestmodeArrs as  $key => $value) {
                        $novalnetTestmodeId = ($key == 'novalnet_cc_testmode') ? 'novalnetcreditcard' : str_replace(['_', 'testmode'], '', $key);
                        $paymentId = Shop::Container()->getDB()->query('SELECT cModulId FROM tpluginzahlungsartklasse WHERE cClassName LIKE "%' . $novalnetTestmodeId . '" ', 1);
                        $splRejectedPaymentId = str_replace('/', '', $paymentId->cModulId);
                        $nnScriptHead = <<<HTML
                        <input type='hidden' id='{$splRejectedPaymentId}' name='nn_display_testmode[]' value='{$value}'>
HTML;
                    }
                    pq('head')->append($nnScriptHead);
                }
            }
        }
        
        // Displaying cc logos on the payment page
        $nnCreditcardLogos = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getConfig()->getValue('novalnet_cc_accepted_card_types');
        
        if (!empty($nnCreditcardLogos)) {

            $nnLogos = array_filter($nnCreditcardLogos);
            if (!empty($nnLogos)) {
                foreach ($smarty->tpl_vars['Zahlungsarten']->value as $payment) {
                    if (strpos($payment->cModulId, 'novalnetkredit/debitkarte')) {
                        foreach ($nnLogos as $logos => $value) {
                            $ccLogo[$logos] = $pluginPath . 'paymentmethod/images/novalnet_' . $value . '.png';
                        }
                        
                        $logoQuery = http_build_query($ccLogo, '', '&');
                        $paymentLogoAlt = $payment->angezeigterName[$_SESSION['cISOSprache']];
                        $nnScriptHead = <<<HTML
                        <input type='hidden' id='nn_logo_alt' value='{$paymentLogoAlt}'>
                        <input type='hidden' id='nn_cc_payment_id' value='{$payment->kZahlungsart}'>
                        <input type='hidden' id='nn_logos' value='{$logoQuery}'>
HTML;
                    }
                    if (strpos($payment->cModulId, 'novalnet')) {
                        $nnScriptHead .= <<<HTML
                        <input type='hidden' id='nn_testmode_text' value='{$testmodeLang}'>
                        <script type='text/javascript' src='{$pluginPath}frontend/js/novalnet_cc_logo.js'></script>
                        <link rel="stylesheet" type="text/css" href="{$pluginPath}paymentmethod/css/novalnet_payment_form.css">
HTML;
                        
                        pq('head')->append($nnScriptHead);
                        break;
                    }
                }
            }
        }
        }
    }
    
    /**
     * Display the Barzahlen slip on success page
     *
     * @param  object  $smarty
     * @return none
     */
    public function displayNnCashpaymentSlip(object $smarty): void
    {
        if (!empty($_SESSION['kBestellung'])) {
            
            $order = new Bestellung($_SESSION['kBestellung']);
            $paymentModule = Shop::Container()->getDB()->query('SELECT cModulId FROM tzahlungsart WHERE kZahlungsart ="' . $order->kZahlungsart . '"', 1);

            // Verifies if the cashpayment token is set and loads the slip from Barzahlen accordingly.
            if ($paymentModule && strpos($paymentModule->cModulId, 'novalnetbarzahlen/viacash') !== false && !empty($_SESSION['novalnet_cashpayment_token'])) {

                pq('body')->append('<script src="'. $_SESSION['novalnet_cashpayment_checkout_js'] . '"
                                            class="bz-checkout"
                                            data-token="'. $_SESSION['novalnet_cashpayment_token'] . '"
                                            data-auto-display="true">
                                    </script>
                                    <style type="text/css">
                                        #bz-checkout-modal { position: fixed !important; }
                                        #barzahlen_button {width: max-content; margin-top: -30px !important; margin-bottom: 5% !important; margin-left: 20px !important; }
                                    </style>');

                pq('#order-confirmation')->append('<button id="barzahlen_button" class="bz-checkout-btn" onclick="javascript:bzCheckout.display();">' . $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_slipurl') . '</button>');

                unset($_SESSION['novalnet_cashpayment_token']);
                unset($_SESSION['novalnet_cashpayment_checkout_js']);
            }
        }
    }
    
    /**
     * Remove the payment details on handleadditional template page
     *
     * @return none
     */
    public function removeSavedDetails(): void
    {
        // Based on the request from the customer, we remove the card/account details from the additional page 
        if (!empty($_REQUEST['nn_request_type']) && $_REQUEST['nn_request_type'] == 'remove' ) {
            
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('xplugin_novalnet_transaction_details', 'nNntid', $_REQUEST['tid'], ['cTokenInfo' => '', 'cSaveOnetimeToken' => 0]);                                             
        }       
    }
    
    /**
     * Change the WAWI pickup status as 'JA' before payment completion 
     *
     * @param  array $args
     * @return none
     */
    public function changeWawiPickupStatus($args): void
    {
        if(!empty($args['oBestellung']->kBestellung) && strpos($_SESSION['Zahlungsart']->cModulId, 'novalnet') !== false) {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $args['oBestellung']->kBestellung, ['cAbgeholt' => 'Y']); 
        }
        
    }
}
