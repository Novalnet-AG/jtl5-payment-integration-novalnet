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
 * Script: NovalnetPaymentHelper.php
 *
*/
 
namespace Plugin\jtl_novalnet;

use Exception;
use JTL\Plugin\PluginInterface;
use JTL\Shop;
use JTL\Session\Frontend;
use JTL\Catalog\Currency;
use JTL\Plugin\Helper;
use JTL\Helpers\Request;

/**
 * Class NovalnetPaymentHelper
 * @package Plugin\jtl_novalnetag
 */
class NovalnetPaymentHelper
{
    /**
     * @var object
     */
    public $plugin;

    /**
     * NovalnetPaymentHelper constructor.
     */
    public function __construct()
    {
        $this->plugin = $this->getNovalnetPluginObject();
    }
    
    /**
     * Get plugin object
     * 
     * @return object
     */
    public function getNovalnetPluginObject()
    {
        return Helper::getPluginById('jtl_novalnet');
    }
    
    /**
     * Retrieve configuration values stored under Novalnet Plugin 
     *
     * @param  string      $configuration
     * @param  bool|string $paymentName
     * @return mixed
     */
    public function getConfigurationValues(string $configuration, $paymentName = false)
    {
        $configValue = $paymentName ? $paymentName . '_' . $configuration : $configuration;

        if (!empty($this->plugin->getConfig()->getValue($configValue))) {
            
            // Only for the tariff ID field, we extract the value which is separated by tariff value and type
            if ($configValue == 'novalnet_tariffid') {
                $tariffValue = trim($this->plugin->getConfig()->getValue('novalnet_tariffid'));
                $tariffId = explode('-', $tariffValue);
                return $tariffId[0];
            }
            return is_string($this->plugin->getConfig()->getValue($configValue)) ? trim($this->plugin->getConfig()->getValue($configValue)) : $this->plugin->getConfig()->getValue($configValue);
        } 
        
        return null;
    }
    
    /**
     * Returning the list of the European Union countries for checking the country code of Guaranteed consumer 
     *     
     * @return array
     */
    public function getEuropeanRegionCountryCodes(): array
    {
        return ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'UK', 'CH'];
    }
    
    /**
     * Building the required billing and shipping details from customer session
     *     
     * @return array
     */
    public function getRequiredBillingShippingDetails(): array
    {
        // Extracting the billing address from Frontend Module
        $billingAddress = Frontend::getCustomer();      
        
        $billingShippingDetails['billing'] = $billingShippingDetails['shipping'] = [
                                               'street'       => $billingAddress->cStrasse,
                                               'house_no'     => $billingAddress->cHausnummer,
                                               'city'         => $billingAddress->cOrt,
                                               'zip'          => $billingAddress->cPLZ,
                                               'country_code' => $billingAddress->cLand
                                             ];
                                             
        // Extracting the shipping address from the session object
        if (!empty($_SESSION['Lieferadresse'])) {
            
        $shippingAddress = $_SESSION['Lieferadresse'];
        
        $billingShippingDetails['shipping'] = [
                                                'street'       => $shippingAddress->cStrasse,
                                                'house_no'     => $shippingAddress->cHausnummer,
                                                'city'         => $shippingAddress->cOrt,
                                                'zip'          => $shippingAddress->cPLZ,
                                                'country_code' => $shippingAddress->cLand
                                              ];
        }
        
        return $billingShippingDetails;
    }
    
    /**
     * Retrieving the reference details for one-click shopping
     *
     * @param  string $paymentName
     * @return array
     */
    public function getPaymentReferenceValues($paymentName): ?array
    {
        $customerDetails = Frontend::getCustomer();
        
        if (!empty($customerDetails->kKunde)) {
            $storedValues = Shop::Container()->getDB()->query('SELECT nNntid, cStatuswert, cTokenInfo FROM xplugin_novalnet_transaction_details WHERE cZahlungsmethode LIKE "%' . $paymentName . '"  AND cMail = "' . $customerDetails->cMail . '" AND cSaveOnetimeToken = 1 AND cTokenInfo != "" ORDER BY kId DESC LIMIT 3', 2);
            return !empty($storedValues) ? $storedValues : null;
        }
        return null;
    }
    
    /**
     * Convert the order amount from decimal to integer
     *
     * @return int
     */
    public function getOrderAmount(): int
    {
        $convertedOrderAmount = Currency::convertCurrency(Frontend::getCart()->gibGesamtsummeWaren(true), Frontend::getCurrency()->getCode());
        if (empty($convertedOrderAmount)) {
            $convertedOrderAmount = $_SESSION['Warenkorb']->gibGesamtsummeWaren(true);
        }
        $orderAmount = str_replace(',', '.', sprintf('%.2f', $convertedOrderAmount));
        return $orderAmount * 100;
    }
    
    /**
     * Process the database update
     *
     * @param  string $tableName
     * @param  string $keyName
     * @param  string $keyValue
     * @param  object $object
     * @return none
     */
    public function performDbUpdateProcess(string $tableName, $keyName, $keyValue, $object): void
    {
        Shop::Container()->getDB()->update($tableName , $keyName , $keyValue, (object) $object);
    }
    
    /**
     * Unsets the Novalnet payment sessions
     *
     * @return none
     */
    public function novalnetSessionCleanUp(string $paymentName): void
    {
        $sessionValues = array(
                'nn_'.$paymentName.'_request',
                'nn_'.$paymentName.'_payment_response',                
                'nn_comments'
                );
                
        foreach($sessionValues as $sessionVal) {
            unset($_SESSION[$paymentName]);
            unset($_SESSION[$sessionVal]);
        }
    }
    
    /**
     * Get language texts for the fields
     *
     * @param  array $languages
     * @return array
     */
    public function getNnLanguageText(array $languages, $langCode = null): array
    {
        foreach($languages as $lang) {
            $languageTexts[$lang] = $this->plugin->getLocalization()->getTranslation($lang, $langCode);
        }
        return $languageTexts;
    }
    
    /**
     * Get translated text for the provided Novalnet text key 
     *
     * @param string $key
     * @return string
     */
    public function getNnLangTranslationText(string $key, $langCode = null): string
    {
        return $this->plugin->getLocalization()->getTranslation($key, $langCode);
    }
    
    /**
     * Get server or remote address from the global variable
     *
     * @param string $addressType
     * @return mixed
     */
    public function getNnIpAddress($addressType)
    {
        if ($addressType == 'REMOTE_ADDR') {
            # Shop's core function that fetches the remote address
            $remoteAddress = !empty(Request::getRealIP()) ? Request::getRealIP() : $_SERVER[$addressType];
            return $remoteAddress;
        }
        return $_SERVER[$addressType];
    }
}
