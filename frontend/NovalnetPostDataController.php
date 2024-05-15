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
 * Script: NovalnetPostDataController.php
 *
*/

namespace Plugin\jtl_novalnet\frontend;

use JTL\IO\IO;
use JTL\Plugin\PluginInterface;
use JTL\Session\Frontend;
use JTL\Helpers\ShippingMethod;
use JTL\Shop;
use JTL\Catalog\Currency;
use Plugin\jtl_novalnet\NovalnetPaymentHelper;
use JTL\Checkout\Lieferadresse;

/**
 * Class NovalnetPostDataController
 * @package Plugin\jtl_novalnet
 */
class NovalnetPostDataController 
{
    /**
     * @var IO
     */
    private $io;
    /**
     * @var string
     */
    private $request;
    /**
     * @var PluginInterface
     */
    private $plugin;
    /**
     * @var Lieferadresse
     */
    private $walletShippingAddress;
    
    /**
     * NovalnetPostDataController constructor.
     * 
     * @param IO $io
     * @param string $request
     * @param PluginInterface $plugin
     */
    public function __construct(IO $io, string $request, PluginInterface $plugin) {
        $this->io = $io;
        // Register the IO function for wallet payment process
        $this->io->register('novalnetShippingAddressUpdate', [$this, 'novalnetShippingAddressUpdate']);
        $this->io->register('novalnetShippingMethodUpdate', [$this, 'novalnetShippingMethodUpdate']);
        $this->request = json_decode($request, true);
        $this->plugin = $plugin;
        $this->database = Shop::Container()->getDB();
        $this->walletShippingAddress = new Lieferadresse();
        $this->novalnetPaymentHelper = new NovalnetPaymentHelper();
    }
    
    /**
     * Add the chosen shipping method to cart
     *
     * @param int $shippingId
     * @param array $shippingAddress
     * @param int $paymentMethodId
     * @return none
     */
    public function addShippingAmountTocart(int $shippingId, array $shippingAddress, int $paymentMethodId = 0): void
    {
        // Get the shipping method ID
        $shippingMethodId = (int) $shippingId;
        $packagingIds = [];
        $shippingMethodId = (int)$shippingId;
        
        // Set the wallet billing address
        $this->walletShippingAddress->cPLZ = $shippingAddress['postalCode'];
        $this->walletShippingAddress->cLand = $shippingAddress['countryCode'];
        
        // Set the detail in shop session variable
        $_SESSION['Lieferadresse'] = !empty($_SESSION['Lieferadresse']) ?  $_SESSION['Lieferadresse'] : $this->walletShippingAddress;
        
        Frontend::getCart()->loescheSpezialPos(C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG);
        // Get the article based shipping cost
        $articleShippingCosts = ShippingMethod::gibArtikelabhaengigeVersandkostenImWK(
            $shippingAddress['countryCode'],
            Frontend::getCart()->PositionenArr
        );
        foreach ($articleShippingCosts as $articleShippingCost) {
            Frontend::getCart()->erstelleSpezialPos(
                $articleShippingCost->cName, 1, $articleShippingCost->fKosten, Frontend::getCart()->gibVersandkostenSteuerklasse($shippingAddress['countryCode']), C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG, false
            );
        }
        require_once PFAD_ROOT . PFAD_INCLUDES . 'bestellvorgang_inc.php';
        versandartKorrekt($shippingMethodId, ['kVerpackung' => $packagingIds]);
        zahlungsartKorrekt($paymentMethodId);
    }
    
    /**
     * Update the shipping address via payment sheet
     *
     * @return string
     */
    public function novalnetShippingAddressUpdate(): string
    {
        // Get the cart details
        $cartDetails = Frontend::getCart();
        $taxDetails = Frontend::getCart()->gibSteuerpositionen();
        $shippingDetails = [];
        $totalProductAmount = 0;
        // Get the details
        $articleDetails = $this->getArticleDetails($cartDetails);
        foreach($articleDetails as $article) {
            if($article['type'] == 'LINE_ITEM') {
                $totalProductAmount += $article['amount'];
            }
        }
        // Shipping Address update
        if(!empty($this->request['params'][0]['novalnetWalletShippingAddress'])) {
            // Get possible shipping method to the payment methods
            $possibleShippingMethods = Shop::Container()->getDB()->query('select v.cName, v.fPreis, v.kVersandart, v.eSteuer from tversandart v, tversandartzahlungsart vz, tzahlungsart z WHERE vz.kZahlungsart = z.kZahlungsart AND vz.kVersandart = v.kVersandart AND  cModulId LIKE "%' . strtolower($this->request['params'][0]['novalnetWalletShippingAddress']['paymentType']) . '" AND v.cLaender LIKE "%' . $this->request['params'][0]['novalnetWalletShippingAddress']['countryCode'] . '%" ', 2);
            // Set the shiiping method array
            if(!empty($possibleShippingMethods)) {
                $initialShippingMethodAmount = ($possibleShippingMethods[0]->eSteuer == 'netto') ? ((($taxDetails[0]->fUst/100) * $possibleShippingMethods[0]->fPreis) + $possibleShippingMethods[0]->fPreis) : ($possibleShippingMethods[0]->fPreis);
                $initialShippingAmount = Currency::convertCurrency(round($initialShippingMethodAmount * 100), Frontend::getCurrency()->getCode());
                foreach($possibleShippingMethods as $shippingMethod) {
                    $shippingMethodAmount = ($shippingMethod->eSteuer == 'netto') ? ((($taxDetails[0]->fUst/100) * $shippingMethod->fPreis) + $shippingMethod->fPreis) : ($shippingMethod->fPreis);
                    $convertedOrderAmount = Currency::convertCurrency(round($shippingMethodAmount * 100), Frontend::getCurrency()->getCode());
                    $shippingDetails[] = array (
                        'identifier' => $shippingMethod->cName,
                        'label'      => $shippingMethod->cName, 
                        'amount'     => (int) ($convertedOrderAmount),
                        'detail'     => ''
                    );
                }
                $articleDetails[] = array('label' => $possibleShippingMethods[0]->cName, 'amount' => (int) $initialShippingAmount, 'type' => 'SUBTOTAL');
                // Set the coupon information
                $availableCoupons = ['Kupon', 'VersandKupon', 'NeukundenKupon'];
                foreach($availableCoupons as $coupon) {
                    if(!empty($_SESSION[$coupon])) {
                        $couponAmount = '-' . round(($_SESSION[$coupon]->fWert * 100));
                        if($_SESSION[$coupon]->cWertTyp == 'prozent') {
                            $couponAmount = '-' . round($totalProductAmount * ($_SESSION[$coupon]->fWert/100));
                        }
                        if($_SESSION[$coupon] == 'VersandKupon') {
                            $couponAmount = '-' . round($possibleShippingMethods[0]->fPreis * 100);
                        }
                        $convertCouponAmount = Currency::convertCurrency($couponAmount, Frontend::getCurrency()->getCode());
                        $articleDetails[] = array('label' => $_SESSION[$coupon]->cName, 'amount' => (int) $convertCouponAmount, 'type' => 'SUBTOTAL');
                    }
                }
                // Set the Additional payment fee
                $paymentMethodInfo = $this->getAdditionalPaymentFee($this->request['params'][0]['novalnetWalletShippingAddress']['paymentType'] , $possibleShippingMethods[0]->kVersandart);
                if(!empty($paymentMethodInfo)) {
                    if(!empty($paymentMethodInfo['paymentFee'])) {
                        $articleDetails[] = array('label' => 'Gebühr', 'amount' => $paymentMethodInfo['paymentFee'], 'type' => 'SUBTOTAL');
                    }
                    $this->addShippingAmountTocart($possibleShippingMethods[0]->kVersandart, $this->request['params'][0]['novalnetWalletShippingAddress'], $paymentMethodInfo['paymentMethodId']);
                }
                
                $_SESSION['shippingAddress'] = $this->request['params'][0]['novalnetWalletShippingAddress'];
            }
        }
        // Set the order amount
        $convertedOrderAmount = Currency::convertCurrency(Frontend::getCart()->gibGesamtsummeWaren(true), Frontend::getCurrency()->getCode());
        if (($convertedOrderAmount) == '') {
            $convertedOrderAmount = $_SESSION['Warenkorb']->gibGesamtsummeWaren(true);
        }
        $shippingAddressChange = array(
            'amount'           => $this->novalnetPaymentHelper->getOrderAmount(),
            'shipping_address' => $shippingDetails,
            'article_details'  => $articleDetails,
        );
        return json_encode($shippingAddressChange);
    }
    
    /**
     * Update the shipping method via payment sheet
     *
     * @return string
     */
    public function novalnetShippingMethodUpdate(): string
    {
        $articleDetails = [];
        $totalProductAmount = 0;
        if(!empty($this->request['params'][0]['novalnetWalletShippingMethod'])) {
            // Get the details
            $cartDetails = Frontend::getCart();
            $taxDetails = Frontend::getCart()->gibSteuerpositionen();
            $articleDetails = $this->getArticleDetails($cartDetails);
            foreach($articleDetails as $article) {
                if($article['type'] == 'LINE_ITEM') {
                    $totalProductAmount += $article['amount'];
                }
            }
            $result = $this->database->select('tversandart', 'cName', $this->request['params'][0]['novalnetWalletShippingMethod']['label']);
            $shippingMethodAmount = ($result->eSteuer == 'netto') ? ((($taxDetails[0]->fUst/100) * $result->fPreis) + $result->fPreis) : ($result->fPreis);
            $convertedOrderAmount = Currency::convertCurrency(round($shippingMethodAmount * 100), Frontend::getCurrency()->getCode());
            $articleDetails[] = array('label' => $result->cName, 'amount' => (int) $convertedOrderAmount, 'type' => 'SUBTOTAL');
            // Set the coupon information
            $availableCoupons = ['Kupon', 'VersandKupon', 'NeukundenKupon'];
            foreach($availableCoupons as $coupon) {
                if(!empty($_SESSION[$coupon])) {
                    $couponAmount = '-' . round(($_SESSION[$coupon]->fWert * 100));
                    if($_SESSION[$coupon]->cWertTyp == 'prozent') {
                        $couponAmount = '-' . round($totalProductAmount * ($_SESSION[$coupon]->fWert/100));
                    }
                    if($_SESSION[$coupon] == 'VersandKupon') {
                        $couponAmount = '-' . round($result->fPreis * 100);
                    }
                    $convertCouponAmount = Currency::convertCurrency($couponAmount, Frontend::getCurrency()->getCode());
                    $articleDetails[] = array('label' => $_SESSION[$coupon]->cName, 'amount' => (int) $convertCouponAmount, 'type' => 'SUBTOTAL');
                }
            }
            $paymentMethodInfo = $this->getAdditionalPaymentFee($this->request['params'][0]['novalnetWalletShippingMethod']['paymentType'] ,$result->kVersandart);
            if(!empty($paymentMethodInfo)) {
                if(!empty($paymentMethodInfo['paymentFee'])) {
                    $articleDetails[] = array('label' => $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_payment_charge'), 'amount' => $paymentMethodInfo['paymentFee'], 'type' => 'SUBTOTAL');
                }
                $this->addShippingAmountTocart($result->kVersandart, $_SESSION['shippingAddress'], $paymentMethodInfo['paymentMethodId']);
            }
        }
        $shippingMethodUpdate = array(
            'amount'           => $this->novalnetPaymentHelper->getOrderAmount(),
            'article_details'  => $articleDetails
        );
        return json_encode($shippingMethodUpdate);
    }
    
    /**
     * Get the Novalnet wallet response
     *
     * @return none
     */
    public function handlePostData(): void
    {
        if(!empty($this->request['params'][0]['novalnetWalletResponse'])) {
            $_SESSION['novalnetWalletResponse'] = $this->request['params'][0]['novalnetWalletResponse'];
        }
    }
    
    /**
     * Get the article details from the cart
     *
     * @param object $cartDetails
     * @return array
     */
    public function getArticleDetails(object $cartDetails): array
    {
        $articleDetails = [];
        // Get article details
        $taxDetails = Frontend::getCart()->gibSteuerpositionen();
        $taxAmount = $vatName = 0;
          if(!empty($taxDetails)) {
            foreach($taxDetails as $taxDetail) {
                $vatName = $taxDetail->cName;
                $int_var = (int)filter_var($taxDetail->cPreisLocalized, FILTER_SANITIZE_NUMBER_INT);               
                $taxAmount += $int_var ;
            }
        }
        $positionArr = (array) $cartDetails->PositionenArr;
        if(!empty($positionArr)) {
            foreach($positionArr as $positionDetails) {
                if(!empty($positionDetails->kArtikel)) {
                    $productName = !empty($positionDetails->Artikel->cName) ? html_entity_decode($positionDetails->Artikel->cName) : html_entity_decode($positionDetails->cName);
                    $productPrice = (floatval($positionDetails->fVK[0]) * 100);   
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
        }
        return $articleDetails;
    }
    
    /**
     * Get the article details from the cart
     *
     * @param string $paymentType
     * @param int $sippingMethodId
     * @return array
     */
    public function getAdditionalPaymentFee(string $paymentType, int $sippingMethodId): array
    {
        $paymentMethodId = 0;
        $paymentInfo = Shop::Container()->getDB()->query('SELECT kZahlungsart, cModulId FROM tzahlungsart WHERE  cModulId LIKE "%' . strtolower($paymentType) . '" ', 2);
                foreach($paymentInfo as $payment) {
                    $result = Shop::Container()->getDB()->select('tversandartzahlungsart', ['kZahlungsart', 'kVersandart'], [$payment->kZahlungsart, $sippingMethodId]);
                    if(!empty($result->fAufpreis)) {
                        $paymentMethodId = $payment->kZahlungsart;
                        return ['paymentMethodId' => $paymentMethodId, 'paymentFee' => ($result->fAufpreis * 100)];
                    }
                }
        return ['paymentMethodId' => 0, 'paymentFee' => 0];
    }
}
