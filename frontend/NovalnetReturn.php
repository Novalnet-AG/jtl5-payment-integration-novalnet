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
 * Script: NovalnetReturn.php
 *
*/

use JTL\Shop;
use JTL\Plugin\PluginInterface;
use \Plugin\jtl_novalnet\frontend\NovalnetReturnController;
use JTL\Plugin\Helper;

$controller = new NovalnetReturnController(Helper::getPluginById('jtl_novalnet'));
$controller->finalProcess();
