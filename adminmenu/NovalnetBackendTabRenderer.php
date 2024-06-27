<?php
/**
 * This file used for rendering the Novalnet pages
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: NovalnetBackendTabRenderer.php
 *
*/

namespace Plugin\jtl_novalnet\adminmenu;

use InvalidArgumentException;
use JTL\Plugin\PluginInterface;
use JTL\Shop;
use JTL\DB\DbInterface;
use JTL\DB\ReturnType;
use JTL\Pagination\Pagination;
use JTL\Checkout\Bestellung;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;

/**
 * Class NovalnetBackendTabRenderer
 * @package Plugin\jtl_novalnet\adminmenu
 */
class NovalnetBackendTabRenderer
{
    /**
     * @var PluginInterface
     */
    private $plugin;
    
    /**
     * @var DbInterface
     */
    private $db;
    
    /**
     * @var JTLSmarty
     */
    private $smarty;
    
    /**
     * @var NovalnetPaymentGateway
     */
    private $novalnetPaymentGateway;
    
    /**
     * NovalnetBackendTabRenderer constructor.
     * @param PluginInterface $plugin
     * @param DbInterface     $db
     */
    public function __construct(PluginInterface $plugin, DbInterface $db)
    {
        $this->plugin = $plugin;
        $this->db = $db;
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();
    }
    
    /**
     * @param  string    $tabName
     * @param  int       $menuID
     * @param  JTLSmarty $smarty
     * @return string
     * @throws \SmartyException
     */
    public function renderNovalnetTabs(string $tabName, int $menuID, JTLSmarty $smarty): string
    {
        $this->smarty = $smarty;
        
        if ($tabName == 'Info') {
            return $this->renderNovalnetInfoPage();
        } elseif ($tabName == 'Bestellungen') {
            return $this->renderNovalnetOrdersPage($menuID);
        } else {
            throw new InvalidArgumentException('Cannot render tab ' . $tabName);
        }
    }
    
    /**
     * Display the Novalnet info template page 
     * 
     * @return string
     */
    private function renderNovalnetInfoPage(): string
    {
        $request = $_REQUEST;
        $novalnetRequestType = !empty($request['nn_request_type']) ? $request['nn_request_type'] : null;
        $langCode = ($_SESSION['AdminAccount']->language == 'de-DE') ? 'ger' : 'eng';
        $configuredWebhookUrl = $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_webhook_url');
        $novalnetWebhookUrl = !empty($configuredWebhookUrl) ? $configuredWebhookUrl : Shop::getURL() . '/?novalnet_webhook';
        
        if (!empty($novalnetRequestType)) {
            // Based on the request type, we either auto-configure the merchant settings or configure the webhook URL
            if ($novalnetRequestType == 'autofill') {
                $this->handleMerchantAutoConfig($request);
            } elseif ($novalnetRequestType == 'configureWebhook') {
                $this->configureWebhookUrl($request);
            } 
        }                
        
        return $this->smarty->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
                            ->assign('languageTexts', $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_notification_text', 'jtl_novalnet_configure_webhook', 'jtl_novalnet_webhook_alert_text', 'jtl_novalnet_webhook_notification_text', 'jtl_novalnet_webhook_error_text', 'jtl_novalnet_webhook_configuration_tooltip', 'jtl_novalnet_webhook_notification', 'jtl_novalnet_info_page_text'], $langCode))
                            ->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
                            ->assign('webhookUrl', $novalnetWebhookUrl)
                            ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/novalnet_info.tpl');
    }
    
    /**
     * Display the Novalnet order template
     * 
     * @param  int    $menuID
     * @return string
     */
    private function renderNovalnetOrdersPage(int $menuID): string
    {
        $request = $_REQUEST;
        $novalnetRequestType = !empty($request['nn_request_type']) ? $request['nn_request_type'] : null;
        
        if (!empty($novalnetRequestType)) {
            $this->displayNovalnetorderDetails($request['order_no'], $menuID);
        }
        
        $orders       = [];
        $nnOrderCount = $this->db->queryPrepared('SELECT cNnorderid FROM xplugin_novalnet_transaction_details', [], ReturnType::AFFECTED_ROWS);
        $pagination   = (new Pagination('novalnetorders'))->setItemCount($nnOrderCount)->assemble();
        $langCode     = ($_SESSION['AdminAccount']->language == 'de-DE') ? 'ger' : 'eng';
        
        $orderArr = $this->db->queryPrepared('SELECT DISTINCT ord.kBestellung FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov WHERE ord.cBestellNr = nov.cNnorderid ORDER BY ord.kBestellung DESC LIMIT ' . $pagination->getLimitSQL(), [], ReturnType::ARRAY_OF_OBJECTS);
        
        foreach ($orderArr as $order) {
            $orderId = (int) $order->kBestellung;
            $ordObj  = new Bestellung($orderId);
            $ordObj->fuelleBestellung(true, 0, false);
            $orders[$orderId] = $ordObj;
        }
        
        if ($_SESSION['AdminAccount']->language == 'de-DE') {
            $paymentStatus = ['5' => 'teilversendet', '4' => 'versendet', '3' => 'bezahlt', '2' => 'in Bearbeitung' , '1' => 'neu' , '-1' => 'storniert'];
        } else {
            $paymentStatus = ['5' => 'partially delivered', '4' => 'shipped', '3' => 'paid', '2' => 'in progress' , '1' => 'new' , '-1' => 'cancelled'];
        }
        
        return $this->smarty->assign('orders', $orders)
                            ->assign('pagination', $pagination)
                            ->assign('pluginId', $this->plugin->getID())
                            ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
                            ->assign('paymentStatus', $paymentStatus)
                            ->assign('hash', 'plugin-tab-' . $menuID)
                            ->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
                            ->assign('languageTexts', $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_order_number', 'jtl_novalnet_customer_text', 'jtl_novalnet_payment_name_text', 'jtl_novalnet_wawi_pickup', 'jtl_novalnet_total_amount_text', 'jtl_novalnet_order_creation_date', 'jtl_novalnet_orders_not_available', 'jtl_novalnet_order_status'], $langCode))
                            ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/novalnet_orders.tpl');
    }
    
    /**
     * Handling of the merchant auto configuration process
     * 
     * @param  array $post
     * @return none
     */
    private function handleMerchantAutoConfig(array $post): void
    {
        $autoConfigRequestParams = [];
        $autoConfigRequestParams['merchant']['signature'] = $post['nn_public_key'];
        $autoConfigRequestParams['custom']['lang'] = ($_SESSION['AdminAccount']->language == 'de-DE') ? 'DE' : 'EN';
        
        $responseData = $this->novalnetPaymentGateway->performServerCall($autoConfigRequestParams, 'merchant_details', $post['nn_private_key']);
        print json_encode($responseData);
        exit;
    }
    
    
    /**
     * Configuring webhook URL in admin portal
     * 
     * @param  array  $post
     * @return none
     */
    private function configureWebhookUrl(array $post): void
    {
        $webhookRequestParams = [];
        $webhookRequestParams['merchant']['signature'] = $post['nn_public_key'];
        $webhookRequestParams['webhook']['url']        = $post['nn_webhook_url'];
        $webhookRequestParams['custom']['lang']        = ($_SESSION['AdminAccount']->language == 'de-DE') ? 'DE' : 'EN';
        
        $responseData = $this->novalnetPaymentGateway->performServerCall($webhookRequestParams, 'webhook_configure', $post['nn_private_key']);
        
        // Upon successful intimation in Novalnet server, we also store it in the internal DB
        if ($responseData['result']['status'] == 'SUCCESS') {
            $this->novalnetPaymentGateway->novalnetPaymentHelper->performDbUpdateProcess('tplugineinstellungen', 'cName', 'novalnet_webhook_url', ['cWert' => $post['nn_webhook_url']]);
        }
        
        print json_encode($responseData);
        exit;
    }
    
    /**
     * Display the Novalnet transaction details template
     * 
     * @param  mixed  $orderNo
     * @param  int    $menuID
     * @return string
     */
    private function displayNovalnetorderDetails($orderNo, int $menuID): string
    {
        $getOrderComment = $this->db->queryPrepared('SELECT ord.cKommentar, ord.kSprache FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov ON ord.cBestellNr = nov.cNnorderid WHERE cNnorderid = :cNnorderid', ['cNnorderid' => $orderNo], ReturnType::SINGLE_OBJECT);
                
        $langCode = ($getOrderComment->kSprache == 2) ? 'eng' : 'ger';
        
        $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($orderNo, $langCode);
        
        $smartyVar = $this->smarty->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
                                  ->assign('orderNo', $orderNo)
                                  ->assign('languageTexts',$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_invoice_payments_order_number_reference'], $langCode))
                                  ->assign('orderComment', $getOrderComment)
                                  ->assign('menuId', '#plugin-tab-' . $menuID)
                                  ->assign('instalmentDetails', $instalmentInfo)
                                  ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/novalnet_order_details.tpl');
                                  
        print $smartyVar;
        exit;
    }
}
