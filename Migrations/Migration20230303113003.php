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
        $isCallbackTableExists = $this->getDB()->query("SHOW TABLES LIKE '%xplugin_novalnet_callback%'");
        if ($isCallbackTableExists) {
            $previousCallBackDetails = $this->getDB()->query("SELECT nc.nCallbackTid callbackTid, sum(nc.nCallbackAmount) callbackAmount FROM xplugin_novalnet_callback nc, xplugin_novalnet_transaction_details nt WHERE nc.nCallbackTid=nt.nNntid group by nc.nCallbackTid", 2);
            foreach ($previousCallBackDetails as $previousCallBackDetail) {
                $previousCallBackDetails = $this->getDB()->query("UPDATE xplugin_novalnet_transaction_details SET nCallbackAmount = '$previousCallBackDetail->callbackAmount' WHERE nNntid = '$previousCallBackDetail->callbackTid' and nCallbackAmount IS NULL LIMIT 1");
            }
            // After updating the values to xplugin_novalnet_transaction_details from callback history table and the delete the below table
            $this->execute('DROP TABLE `xplugin_novalnet_callback`');
        }
    }
    
    public function down()
    {
        
    }
}
