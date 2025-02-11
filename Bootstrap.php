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
 * Script: Bootstrap.php
 *
*/
 
namespace Plugin\jtl_novalnet;

use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_novalnet\frontend\NovalnetHookHandler;
use Plugin\jtl_novalnet\adminmenu\NovalnetBackendTabRenderer;
use Plugin\jtl_novalnet\frontend\NovalnetPostDataController;

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
        
        if (Shop::isFrontend()) {
            
            $novalnetHookHandler        = new NovalnetHookHandler($this->getPlugin());            
            
            // Custom frontend operations for the Novalnet Plugin
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB, [$novalnetHookHandler, 'orderStatusPage']);
            $dispatcher->listen('shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER, [$novalnetHookHandler, 'contentUpdate']);
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE, [$novalnetHookHandler, 'removeSavedDetails']);
            $dispatcher->listen('shop.hook.' . \HOOK_JTL_PAGE, [$novalnetHookHandler, 'accountPage']);
            $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, [$novalnetHookHandler, 'changeWawiPickupStatus']);
            $dispatcher->listen('shop.hook.' . \HOOK_IO_HANDLE_REQUEST, function (array $args) {
                $novalnetPostDataController = new NovalnetPostDataController($args['io'], $args['request'], $this->getPlugin());
                $novalnetPostDataController->handlePostData();
            });
                        
            if (isset($_REQUEST['novalnet_webhook'])) {
                
                // When the Novalnet webhook is triggered and known through URL, we call the appropriate Novalnet webhook handler
                $novalnetWebhookHandler = new NovalnetWebhookHandler($this->getPlugin());
                $dispatcher->listen('shop.hook.' . \HOOK_INDEX_NAVI_HEAD_POSTGET, [$novalnetWebhookHandler, 'handleNovalnetWebhook']);
            }
            
           if (isset($_REQUEST['novalnet_refund'])) {

                // When the Wawi refund workflow is triggered and known through URL, we call the appropriate Novalnet webhook handler
                $novalnetWebhookHandler = new NovalnetWebhookHandler($this->getPlugin());
                $dispatcher->listen('shop.hook.' . \HOOK_INDEX_NAVI_HEAD_POSTGET, [$novalnetWebhookHandler, 'handleNovalnetRefund']);
            }
            
            if (isset($_REQUEST['novalnet_wawi_invoice'])) {

                // When the Wawi generates the invoice workflow is triggered and known through URL, we call the appropriate Novalnet webhook handler
                $novalnetWebhookHandler = new NovalnetWebhookHandler($this->getPlugin());
                $dispatcher->listen('shop.hook.' . \HOOK_INDEX_NAVI_HEAD_POSTGET, [$novalnetWebhookHandler, 'handleNovalnetInvoice']);
            }
        }
    }

    /**
     * @param string    $tabName
     * @param int       $menuID
     * @param JTLSmarty $smarty
     * @return string
     */
    public function renderAdminMenuTab(string $tabName, int $menuID, JTLSmarty $smarty): string
    {
        // Render Novalnet Plugin's backend tabs and it's related functions
        $backendRenderer = new NovalnetBackendTabRenderer($this->getPlugin(), $this->getDB());
        return $backendRenderer->renderNovalnetTabs($tabName, $menuID, $smarty);
    }
}

