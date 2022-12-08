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
use JTL\Language\LanguageHelper;
use Plugin\jtl_novalnet\paymentmethod\NovalnetPaymentGateway;

/**
 * Class NovalnetBackendTabRenderer
 * @package Plugin\jtl_novalnet
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
     */
    public function __construct(PluginInterface $plugin, DbInterface $db)
    {
        $this->plugin = $plugin;
        $this->db = $db;
        $this->novalnetPaymentGateway = new NovalnetPaymentGateway();
    }
    
    /**
     * @param string    $tabName
     * @param int       $menuID
     * @param JTLSmarty $smarty
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
        $novalnetRequestType = !empty($_REQUEST['nn_request_type']) ? $_REQUEST['nn_request_type'] : null;
        $langCode = ($_SESSION['AdminAccount']->language == 'de-DE') ? 'ger' : 'eng';
        $novalnetWebhookUrl = !empty($this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_webhook_url')) ? $this->novalnetPaymentGateway->novalnetPaymentHelper->getConfigurationValues('novalnet_webhook_url') : Shop::getURL() . '/?novalnet_webhook';
        
        if (!empty($novalnetRequestType)) {
            // Based on the request type, we either auto-configure the merchant settings or configure the webhook URL
            if ($novalnetRequestType == 'autofill') {
                $this->handleMerchantAutoConfig($_REQUEST);
            } elseif ($novalnetRequestType == 'configureWebhook') {
                $this->configureWebhookUrl($_REQUEST);
            } 
        }                
        
        return $this->smarty->assign('pluginDetails', $this->plugin)
                            ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
                            ->assign('languageTexts', $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_notification_text', 'jtl_novalnet_configure_webhook', 'jtl_novalnet_webhook_alert_text', 'jtl_novalnet_webhook_notification_text', 'jtl_novalnet_webhook_error_text', 'jtl_novalnet_webhook_configuration_tooltip', 'jtl_novalnet_due_date_error_text', 'jtl_novalnet_guarantee_min_amount_error_text', 'jtl_novalnet_instalment_min_amount_error_text', 'jtl_novalnet_guarantee_condition_text', 'jtl_novalnet_instalment_condition_text', 'jtl_novalnet_webhook_notification', 'jtl_novalnet_paypal_configuration_text',  'jtl_novalnet_info_page_text', 'jtl_novalnet_multiselect_option_text'], $langCode))
                            ->assign('shopLang', $_SESSION['AdminAccount']->language)
                            ->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
                            ->assign('webhookUrl', $novalnetWebhookUrl)
                            ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/novalnet_info.tpl');
    }
    
    /**
     * Display the Novalnet order template
     * 
     * @param int $menuID
     * @return string
     */
    private function renderNovalnetOrdersPage(int $menuID): string
    {
        $novalnetRequestType = !empty($_REQUEST['nn_request_type']) ? $_REQUEST['nn_request_type'] : null;
        
        if (!empty($novalnetRequestType)) {
            $this->displayNovalnetorderDetails($_REQUEST, $menuID);
        }
        
        $orders       = [];
        $nnOrderCount = $this->db->query('SELECT cNnorderid FROM xplugin_novalnet_transaction_details', ReturnType::AFFECTED_ROWS);
        $pagination   = (new Pagination('novalnetorders'))->setItemCount($nnOrderCount)->assemble();
        $langCode     = ($_SESSION['AdminAccount']->language == 'de-DE') ? 'ger' : 'eng';
        
        $orderArr = $this->db->query('SELECT DISTINCT ord.kBestellung FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov WHERE ord.cBestellNr = nov.cNnorderid ORDER BY ord.kBestellung DESC LIMIT ' . $pagination->getLimitSQL(), ReturnType::ARRAY_OF_OBJECTS);
        
        foreach ($orderArr as $order) {
            $orderId = (int) $order->kBestellung;
            $ordObj  = new Bestellung($orderId);
            $ordObj->fuelleBestellung(true, 0, false);
            $orders[$orderId] = $ordObj;
        }
        
        if ($_SESSION['AdminAccount']->language == 'de-DE') {
            $paymentStatus = ['5' => 'teilversendet', '4' => 'versendet', '3' => 'bezahlt', '2' => 'in Bearbeitung' , '1' => 'offen' , '-1' => 'Storno'];
        } else {
            $paymentStatus = ['5' => 'partially shipped', '4' => 'shipped', '3' => 'paid', '2' => 'in processing' , '1' => 'open' , '-1' => 'canceled'];
        }
        
        return $this->smarty->assign('orders', $orders)
                            ->assign('pagination', $pagination)
                            ->assign('pluginId', $this->plugin->getID())
                            ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
                            ->assign('paymentStatus', $paymentStatus)
                            ->assign('hash', 'plugin-tab-' . $menuID)
                            ->assign('languageTexts', $this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_order_number', 'jtl_novalnet_customer_text', 'jtl_novalnet_payment_name_text', 'jtl_novalnet_wawi_pickup', 'jtl_novalnet_total_amount_text', 'jtl_novalnet_order_creation_date', 'jtl_novalnet_orders_not_available'], $langCode))
                            ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/novalnet_orders.tpl');
    }
    
    /**
     * Handling of the merchant auto configuration process
     * 
     * @param array $post
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
     * @param array  $post
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
     * @param int $menuID
     * @return string
     */
    private function displayNovalnetorderDetails(array $post, int $menuID): string
    {
        $getOrderComment = $this->db->query('SELECT ord.cKommentar, ord.kSprache FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov ON ord.cBestellNr = nov.cNnorderid WHERE cNnorderid = "' . $post['order_no'] . '"', ReturnType::SINGLE_OBJECT);
                
        $langCode = ($getOrderComment->kSprache == 1) ? 'ger' : 'eng';
        
        $instalmentInfo = $this->novalnetPaymentGateway->getInstalmentInfoFromDb($post['order_no'], $langCode);
        
        $smartyVar = $this->smarty->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
                                  ->assign('orderNo', $post['order_no'])
                                  ->assign('languageTexts',$this->novalnetPaymentGateway->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_invoice_payments_order_number_reference'], $langCode))
                                  ->assign('orderComment', $getOrderComment)
                                  ->assign('menuId', '#plugin-tab-' . $menuID)
                                  ->assign('instalmentDetails', $instalmentInfo)
                                  ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/novalnet_order_details.tpl');
                                  
        print $smartyVar;
        exit;
    }
}

