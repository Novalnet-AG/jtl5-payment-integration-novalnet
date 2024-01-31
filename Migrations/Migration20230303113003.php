<?php
/**
 * This file update the previous Novalnet custom the table
 *
 * @author      Novalnet
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: Migration20210608221920.php
*/

namespace Plugin\jtl_novalnet\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;
use JTL\Shop;
/**
 * Class Migration20230303113003
 * @package Plugin\jtl_novalnet\Migrations
 */
class Migration20230303113003 extends Migration implements IMigration
{
    /**
     * Remove the column from the Novalnet transaction details table during the novalnet plugin installation
     *
     */
    public function up()
    {
        $this->execute('ALTER TABLE xplugin_novalnet_transaction_details 
                        ADD COLUMN `nCallbackAmount` INT(11) DEFAULT NULL,
                        DROP COLUMN cSaveOnetimeToken, 
                        DROP COLUMN cTokenInfo'
        );
        // Check if the callback history table already exist or not
        $isCallbackTableExists = Shop::Container()->getDB()->queryPrepared("SHOW TABLES LIKE :callback", [":callback" => "%xplugin_novalnet_callback%"]);
        if ($isCallbackTableExists) {
            $previousCallBackDetails = Shop::Container()->getDB()->queryPrepared("SELECT nc.nCallbackTid callbackTid, sum(nc.nCallbackAmount) callbackAmount FROM xplugin_novalnet_callback nc, xplugin_novalnet_transaction_details nt WHERE nc.nCallbackTid = :nCallbackTid group by :nCallbackTid", [":nCallbackTid" => "nt.nNntid", ":nCallbackTid" => "nc.nCallbackTid"]);
			if (is_array($previousCallBackDetails) || is_object($previousCallBackDetails)) {
				foreach ($previousCallBackDetails as $previousCallBackDetail) {
					$previousCallBackDetails = Shop::Container()->getDB()->queryPrepared("UPDATE xplugin_novalnet_transaction_details SET nCallbackAmount = :callbackamount WHERE nNntid = :nCallbackTid and nCallbackAmount IS NULL LIMIT 1", [":callbackamount" => "$previousCallBackDetail->callbackAmount", ":nCallbackTid" => "$previousCallBackDetail->callbackTid"]);      
				}
			}
            // After updating the values to xplugin_novalnet_transaction_details from callback history table and the delete the below table
            $this->execute('DROP TABLE `xplugin_novalnet_callback`');
        }
    }
    
    public function down()
    {
        
    }
}
