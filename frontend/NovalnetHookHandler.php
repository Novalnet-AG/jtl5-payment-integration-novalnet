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
use JTL\Session\Frontend;
use JTL\Customer\Customer;
use JTL\Catalog\Currency;
use JTL\Helpers\ShippingMethod;
use JTL\Helpers\Tax;
use JTL\Checkout\Versandart;


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
            $orderId = Shop::Smarty()->tpl_vars['Bestellung']->value->kBestellung;
            $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($orderNo, $lang, $orderId);
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
            $this->checkoutPage($smarty);
        } elseif(Shop::getPageType() == \PAGE_BESTELLABSCHLUSS) {
            $this->displayNnCashpaymentSlip($smarty);
        } elseif(Shop::getPageType() == \PAGE_ARTIKEL || Shop::getPageType() == \PAGE_WARENKORB) {
            $this->displayWalletPayment($args);
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
                        <script type='text/javascript' src='{$pluginPath}frontend/js/novalnet_cc_logo.js'  integrity='sha384-uWLphXhq/ALho7q+IMBIlgwXCUqHAwV3xh0/0kl+6aGSrfdjlFbsF8BQaaPLgvZp'></script>
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

     /**
     * Display the wallet payment button on the product and cart page
     *
     * @param array $args
     * @return none
     */
    public function displayWalletPayment(array $args): void
    {
        // Get the cart details from the cart page
        $cartDetails = Frontend::getCart();
        // Get the payment sheet Line Items value
        // Tax calculation
        $taxDetails = Frontend::getCart()->gibSteuerpositionen();
        $taxAmount = $vatName = 0;
        if(!empty($taxDetails)) {
            foreach($taxDetails as $taxDetail) {
                $vatName = $taxDetail->cName;
                $int_var = (int)filter_var($taxDetail->cPreisLocalized, FILTER_SANITIZE_NUMBER_INT);               
                $taxAmount += $int_var ;
            }
        }
        // Define the variables
        $finalArticleDetails  = $cartEmpty = '';
        // Get the chosen articlesdetails
        $positionArr = (array) $cartDetails->PositionenArr;
        if(!empty($positionArr)) {
            foreach($positionArr as $positionDetails) {
                if(!empty($positionDetails->kArtikel)) {
                    $productName = !empty($positionDetails->Artikel->cName) ? html_entity_decode($positionDetails->Artikel->cName) : html_entity_decode($positionDetails->cName);
                    $productPrice =  (floatval($positionDetails->fVK[0]) * 100) ;  
                    $productQuantity = !empty($positionDetails->Artikel->nAnzahl) ? $positionDetails->Artikel->nAnzahl : $positionDetails->nAnzahl;
                    $articleDetails[] = array (
                        'label' => '(' . $productQuantity . ' X ' . $productPrice . ') ' . $productName,
                        'amount' => round($productPrice * $productQuantity),
                        'type' => 'LINE_ITEM'
                    );
                }
            }
            // Set the TAX information
            if(!empty($taxAmount)) {
                $articleDetails[] = array('label' => $vatName, 'amount' => floatval($taxAmount), 'type' => 'SUBTOTAL');
            }
            // Set the coupon information
            $availableCoupons = ['Kupon', 'VersandKupon', 'NeukundenKupon'];
            foreach($availableCoupons as $coupon) {
                if(!empty($_SESSION[$coupon])) {
                    $couponAmount = round(($_SESSION[$coupon]->fWert * 100));
                    $convertCouponAmount = Currency::convertCurrency($couponAmount, Frontend::getCurrency()->getCode());
                    $articleDetails[] = array('label' => $_SESSION[$coupon]->cName, 'amount' =>(int) $convertCouponAmount, 'type' => 'SUBTOTAL');
                }
            }
            $finalArticleDetails = htmlentities(json_encode($articleDetails));
        }
        // Installed Plugin path
        $pluginPath  = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getPaths()->getBaseURL();
        // Get the frontend currency
        $currencyName = Frontend::getCurrency()->getCode();
        // Get the order amount
        $orderAmountvalue = $this->novalnetPaymentGateway->novalnetPaymentHelper->getOrderAmount();
        // Get the Google Pay and Apple Pay configuration values
        $paymentTypes = ['novalnet_applepay' => 'APPLEPAY', 'novalnet_googlepay' => 'GOOGLEPAY'];
        $configurationArr = [];
        foreach($paymentTypes as $paymentTypeKey => $paymentTypeValue) {
            if($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues($paymentTypeKey . '_enablemode')) {
                $configurationArr[$paymentTypeValue]['client_key'] =  $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_client_key');
                $configurationArr[$paymentTypeValue]['seller_name'] =  $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues($paymentTypeKey . '_seller_name');
                $configurationArr[$paymentTypeValue]['button_type'] =  $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues($paymentTypeKey . '_button_type');
                $configurationArr[$paymentTypeValue]['button_height'] =  $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues($paymentTypeKey . '_button_height');
                $configurationArr[$paymentTypeValue]['testmode'] =  $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues($paymentTypeKey . '_testmode');
            }
        }
        $configurationData = json_encode($configurationArr);
        // Set the Google Pay merchant ID
        $merchantId = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_googlepay_merchant_id');
        $isEnforceEnabled = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_googlepay_enforce_option');
        // Set the product and cart page guest user country code and button display option
        if(Shop::getPageType() == \PAGE_ARTIKEL || Shop::getPageType() == \PAGE_WARENKORB) {
            // Static value for Wallet payment button are show to the details page
            $displayButton = (Shop::getPageType() == \PAGE_ARTIKEL) ? 0 : 1;
        }
        // Configuration base display the Google Pay and Apple Pay button
        $showGooglepay  = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_googlepay_button_display');
        $_SESSION['show_googlepay'] = $showGooglepay;

        $showApplepay  = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_applepay_button_display');
        $_SESSION['show_applepay'] = $showApplepay;

        $productName = $productPrice = 0;
        // Product name and amount in products details page
        if(empty($cartDetails->PositionenArr)) {
            $productName = !empty($args['smarty']->tpl_vars['Artikel']->value->cName) ? $args['smarty']->tpl_vars['Artikel']->value->cName : '';
            $productPrice = !empty(round($args['smarty']->tpl_vars['Artikel']->value->Preise->fVKBrutto * 100)) ? round($args['smarty']->tpl_vars['Artikel']->value->Preise->fVKBrutto * 100) : 0;
            $orderAmountvalue = $productPrice;
            $cartEmpty = 1;
        }
        // Get the shop URl
        $shopURL = Shop::getURL();
        // Set the product id
        $productId = !empty($args['smarty']->tpl_vars['Artikel']->value->kArtikel) ? $args['smarty']->tpl_vars['Artikel']->value->kArtikel : 0;
        // Payment Eanable Configuration
        $gPayEnable      = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_googlepay_enablemode');
        $applePayEnable  = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_applepay_enablemode');
        // Set it is product page
        $productPage = (Shop::getPageType() == \PAGE_ARTIKEL) ? 1 : 0;
        // Set the Wallet payments
        $applePay = $googlePay = [];
        if($gPayEnable == 'on' && !empty($showGooglepay)) {
            if(Shop::getPageType() == \PAGE_ARTIKEL && in_array('product_details_page', $showGooglepay) || Shop::getPageType() == \PAGE_WARENKORB && in_array('shopping_cart_page', $showGooglepay)) {
                $googlePay = ['GOOGLEPAY'];
            }
        }
        if($applePayEnable == 'on' && !empty($showApplepay)) {
            if(Shop::getPageType() == \PAGE_ARTIKEL && in_array('product_details_page', $showApplepay) || Shop::getPageType() == \PAGE_WARENKORB && in_array('shopping_cart_page', $showApplepay)) {
                $applePay = ['APPLEPAY'];
            }
        }
        $walletPayments = array_merge($googlePay, $applePay);
        $enabledWalletPayment = json_encode($walletPayments);
        
        // Merchant Country code
        $merchantCountryCode = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_googlepay_country_code');
            
        // Assign the template variables
         $nnWalletScript    = '';
         $nnWalletScript    .= <<<HTML
                                <input type='hidden' id='nn_currency' value='{$currencyName}'>
                                <input type='hidden' id='nn_order_amount' value='{$orderAmountvalue}'>
                                <input type='hidden' id='nn_product_name' value='{$productName}'>
                                <input type='hidden' id='nn_product_amount' value='{$productPrice}'>
                                <input type='hidden' id='nn_cart_empty' value='{$cartEmpty}'>
                                <input type='hidden' id='nn_final_article_details' value='{$finalArticleDetails}'>
                                <input type="hidden" id="nn_display_button" value="{$displayButton}">
                                <input type="hidden" id="nn_gpay_enable" value="{$gPayEnable}">
                                <input type="hidden" id="nn_apple_enable" value="{$applePayEnable}">
                                <input type='hidden' id='nn_shop_url' value='{$shopURL}'>
                                <input type='hidden' id='nn_product_id' value='{$productId}'>
                                <input type='hidden' id='nn_product_page' value='{$productPage}'>
                                <input type='hidden' id='nn_page_language' value='{$_SESSION["cISOSprache"]}'>
                                <input type='hidden' id='nn_wallet_payments' value='{$enabledWalletPayment}'>
                                <input type='hidden' id='nn_enforce' value='{$isEnforceEnabled}'>
                                <input type='hidden' id='nn_merchant_country_code' value='{$merchantCountryCode}'>
                                <input type="hidden" name="nn_merchant_id" id="nn_merchant_id" value="{$merchantId}">
                                <script>var configurationData = JSON.parse('{$configurationData}');</script>
HTML;
        // Get Backend based on the configurations
        if(!empty($walletPayments)) {
        $nnWalletScript .= <<<HTML
                    <script type='text/javascript' src='https://cdn.novalnet.de/js/v3/payment.js'></script>
                    <script type='text/javascript' src='{$pluginPath}frontend/js/novalnet_wallet_helper.js'  integrity='sha384-P/zUsepg+4PfPf/+dULRVVU5/JF6+ApCdl9x522g57PrxZHxHi04vt/IQe08Q7+n'></script>
                    <script type='text/javascript' src='{$pluginPath}frontend/js/novalnet_wallet.js'  integrity='sha384-1v8GenpDSIBtKx1oTXsD9IKyzdbpF1JJOwICzHqXYw6CSGL7pDIkbqvA/uQmkpLU' defer></script>
HTML;

        }
        // Method of Connect on hook file to js file
        pq('body')->append($nnWalletScript);
    }

     /**
     * Hide applepay payment in checkout page
     *
     * @param object $smarty
     * @return none
     */
    public function checkoutPage(object $smarty): void
    {
        global $step;

        if (in_array($step, ['Zahlung', 'Versand'])) {
            
        // Get Installed plugin path
        $pluginPath = $this->novalnetPaymentGateway->novalnetPaymentHelper->plugin->getPaths()->getBaseURL();
        foreach ($smarty->tpl_vars['Zahlungsarten']->value as $payment) {
            if (strpos($payment->cModulId, 'novalnetapplepay')) {
                $nnCheckoutPageScript = <<<HTML
                        <input type="hidden" id="nn_applepay_id" value="{$payment->cModulId}">
                        <script type='text/javascript' src='{$pluginPath}paymentmethod/js/novalnet_applepay_hide.js'  integrity='sha384-qyfqZEa2+AFRohQuhVqjm4Oy1di3FE99vBg8e7MOGlHa8GVILu+XHgDpm665Wr8F' defer></script>
HTML;
                // Method of connect on hook file to js file
                pq('body')->append($nnCheckoutPageScript);
            }
        }
        }
    }
}
