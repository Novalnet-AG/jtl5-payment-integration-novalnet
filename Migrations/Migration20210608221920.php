<?php
/**
 * This file create the custom table
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
 * Class Migration20210608221920
 * @package Plugin\jtl_novalnet\Migrations
 */
class Migration20210608221920 extends Migration implements IMigration
{
    /**
     * Create Novalnet transaction details table during the novalnet plugin installation
     *
     */
    public function up()
    {
        $this->execute('CREATE TABLE IF NOT EXISTS `xplugin_novalnet_transaction_details` (
                       `kId` int(10) NOT NULL AUTO_INCREMENT,
                       `cNnorderid` VARCHAR(64) NOT NULL,
                       `nNntid` BIGINT(20) NOT NULL,
                       `cZahlungsmethode` VARCHAR(64) NOT NULL,
                       `cMail` VARCHAR(255) NOT NULL,
                       `cStatuswert` VARCHAR(64),
                       `nBetrag` INT(11) NOT NULL,
                       `cSaveOnetimeToken` TINYINT(1) DEFAULT 0,
                       `cTokenInfo` LONGTEXT DEFAULT NULL,
                       `cAdditionalInfo` LONGTEXT DEFAULT NULL,
                        INDEX (cNnorderid, nNntid, cZahlungsmethode),
                        PRIMARY KEY (`kId`)
                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci'
        );
            
        $this->execute('CREATE TABLE IF NOT EXISTS `xplugin_novalnet_callback` (
                       `kId` INT(10) NOT NULL AUTO_INCREMENT,
                       `cNnorderid` VARCHAR(64) NOT NULL,
                       `nCallbackTid` BIGINT(20) NOT NULL,
                       `nReferenzTid` BIGINT(20) NOT NULL,
                       `cZahlungsmethode` VARCHAR(64) NOT NULL,
                       `dDatum` datetime NOT NULL,
                       `nCallbackAmount` INT(11) DEFAULT NULL,
                       `cWaehrung` VARCHAR(64) DEFAULT NULL,
                        INDEX (cNnorderid),
                        PRIMARY KEY (`kId`)
                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci'
        );
    }
    
    /**
     * Delete Novalnet transaction details table during the novalnet plugin uninstallation
     *
     */
    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `xplugin_novalnet_transaction_details`');
        $this->execute('DROP TABLE IF EXISTS `xplugin_novalnet_callback`');
    }
}
