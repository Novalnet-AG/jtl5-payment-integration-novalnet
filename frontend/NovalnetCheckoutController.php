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
 * Script: NovalnetCheckoutController.php
 *
*/
 
namespace Plugin\jtl_novalnet\frontend;

use JTL\Shop;
use JTL\Plugin\PluginInterface;
use JTL\Session\Frontend;
use JTL\Customer\Customer;
use JTL\Helpers\ShippingMethod;
use JTL\Checkout\Lieferadresse;
use JTL\Checkout\Adresse;
use JTL\Language\LanguageHelper;

/**
 * Class NovalnetCheckoutController
 * @package Plugin\jtl_novalnet
 */
class NovalnetCheckoutController
{
    /**
     * @var ShippingMethod
     */
    private $shippingMethods;
    /**
     * @var array
     */
    private $packagings;
    
    /**
     * NovalnetCheckoutController constructor.
     * 
     * @param PluginInterface $plugin
     */
    public function __construct(PluginInterface $plugin)
    {
        $this->plugin = $plugin;
        $this->shippingMethods = [];
        $this->packagings = [];
    }
    
    /**
     * Render the customized order summary page 
     *
     * @return none
     */
    public function renderSummaryPage()
    {
        if (empty($_SESSION['Kunde'])) {
            $_SESSION['Kunde'] = new Customer();
        }
        if (empty($_SESSION['Lieferadresse'])) {
            $_SESSION['Lieferadresse'] = new Lieferadresse();
        }
        // Set the wallet billing address        
        $_SESSION['Kunde']->cVorname = $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['firstName'];
        $_SESSION['Kunde']->cNachname = $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['lastName'];
        $_SESSION['Kunde']->cMail = !empty($_SESSION['novalnetWalletResponse']['order']['billing']['contact']['email']) ? $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['email'] : $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['email'];
        $_SESSION['Kunde']->cStrasse = $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['addressLines'];
        $_SESSION['Kunde']->cPLZ = $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['postalCode'];
        $_SESSION['Kunde']->cOrt = $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['locality'];
        $_SESSION['Kunde']->cLand = $_SESSION['novalnetWalletResponse']['order']['billing']['contact']['countryCode'];
        $_SESSION['Kunde']->angezeigtesLand = LanguageHelper::getCountryCodeByCountryName($_SESSION['Kunde']->cLand);
       
        // Set the wallet billing address
        $_SESSION['Lieferadresse']->cVorname = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['firstName'];
        $_SESSION['Lieferadresse']->cNachname = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['lastName'];
        $_SESSION['Lieferadresse']->cStrasse = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['addressLines'];
        $_SESSION['Lieferadresse']->cPLZ = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['postalCode'];
        $_SESSION['Lieferadresse']->cOrt = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['locality'];
        $_SESSION['Lieferadresse']->cLand = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['countryCode'];
        $_SESSION['Lieferadresse']->angezeigtesLand = LanguageHelper::getCountryCodeByCountryName($_SESSION['Lieferadresse']->cLand);
        if (!empty($_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['email'])) {
            $_SESSION['Lieferadresse']->cMail = $_SESSION['novalnetWalletResponse']['order']['shipping']['contact']['email'];
        }
        if (!empty($_SESSION['Lieferadresse'])) {
             $shippingMethods = ShippingMethod::getPossibleShippingMethods($_SESSION['Lieferadresse']->cLand, $_SESSION['Lieferadresse']->cPLZ, ShippingMethod::getShippingClasses(Frontend::getCart()), Frontend::getCustomerGroup()->getID());
             $this->shippingMethods = $shippingMethods;
             $this->packagings = ShippingMethod::getPossiblePackagings(Frontend::getCustomerGroup()->getID());
        }
        // Assign smarty varibales
        $templateVariables = [
            'templateMode' => !empty($_SESSION['Warenkorb']->PositionenArr[0]->Artikel->oSuchspecialBild->templateName) ? strtolower($_SESSION['Warenkorb']->PositionenArr[0]->Artikel->oSuchspecialBild->templateName) : 'nova',
            'templateSummaryPage' => $this->plugin->getPaths()->getFrontendPath() . 'template/novalnet_summary_page.tpl',
            'templateCheckoutStep' => ($_SESSION['novalnetWalletResponse']['result']['status']) == 'SUCCESS' ? 'summaryPage' : 'error',
            'paymentMethodName' => ($_SESSION['novalnetWalletResponse']['paymentType'] == 'GOOGLEPAY') ? 'Google Pay' : 'Apple Pay'
        ];
        Shop::Smarty()->assign('templateVariables', $templateVariables)
                    ->assign('Versandarten', $this->shippingMethods)
                    ->assign('Verpackungsarten', $this->packagings)
                    ->assign('pageLang', $_SESSION['cISOSprache'])
                    ->assign('AGB', Shop::Container()->getLinkService()->getAGBWRB(Shop::getLanguage(), Frontend::getCustomerGroup()->getID()));
    }
}
