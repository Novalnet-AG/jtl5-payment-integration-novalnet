<?php 
/**
 * This file refers to the initialization of a plugin for the subsequent use
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: Bootstrap.php
 *
*/
 
namespace Plugin\jtl_novalnet;

use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_novalnet\frontend\NovalnetHookHandler;
use Plugin\jtl_novalnet\src\NovalnetWebhookHandler;
use Plugin\jtl_novalnet\adminmenu\NovalnetBackendTabRenderer;

/**
 * Class Bootstrap
 * @package Plugin\jtl_novalnet
 */
class Bootstrap extends Bootstrapper
{   
    /**
     * Boot additional services for the payment method
     */
    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);
        // Custom frontend operations for the Novalnet Plugin
        if (Shop::isFrontend()) {
            $novalnetHookHandler        = new NovalnetHookHandler($this->getPlugin());
            // Display the Novalnet transaction comments on order status page when payment before order completion option is set to 'Ja'
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB, [$novalnetHookHandler, 'orderStatusPage']); 
            // Loads the Novalnet Payment form on the checkout page  
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPZAHLUNG, [$novalnetHookHandler, 'displayNnPaymentForm']);         
            // Used for the frontend template customization
            $dispatcher->listen('shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER, [$novalnetHookHandler, 'contentUpdate']);
            // Display the Novalnet transaction comments aligned in My Account page of the user
            $dispatcher->listen('shop.hook.' . \HOOK_JTL_PAGE, [$novalnetHookHandler, 'accountPage']);
            // Change the WAWI pickup status as 'JA' before payment completion
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, [$novalnetHookHandler, 'changeWawiPickupStatus']);
            // Change the payment name based on the customer chosen payment in the payment form
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPBESTAETIGUNG, [$novalnetHookHandler, 'updatePaymentName']);
            // Handle the webhook process          
            if (isset($_REQUEST['novalnet_webhook'])) {
                // When the Novalnet webhook is triggered and known through URL, we call the appropriate Novalnet webhook handler
                $novalnetWebhookHandler = new NovalnetWebhookHandler($this->getPlugin());
                $dispatcher->listen('shop.hook.' . \HOOK_INDEX_NAVI_HEAD_POSTGET, [$novalnetWebhookHandler, 'handleNovalnetWebhook']);
            }
            if (isset($_REQUEST['novalnet_refund'])) {
                // When the Novalnet webhook is triggered and known through URL, we call the appropriate Novalnet webhook handler
                $novalnetWebhookHandler = new NovalnetWebhookHandler($this->getPlugin());
                $dispatcher->listen('shop.hook.' . \HOOK_INDEX_NAVI_HEAD_POSTGET, [$novalnetWebhookHandler, 'handleNovalnetRefund']);
            }
        }
    }

    /**
     * Render the Novalnet admin tabs in the shop backend
     * 
     * @param  string    $tabName
     * @param  int       $menuID
     * @param  JTLSmarty $smarty
     * @return string
     */
    public function renderAdminMenuTab(string $tabName, int $menuID, JTLSmarty $smarty): string
    {
        // Render Novalnet Plugin's backend tabs and it's related functions
        $backendRenderer = new NovalnetBackendTabRenderer($this->getPlugin(), $this->getDB());
        return $backendRenderer->renderNovalnetTabs($tabName, $menuID, $smarty);
    }
}
