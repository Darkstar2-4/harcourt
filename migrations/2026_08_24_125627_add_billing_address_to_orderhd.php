<?php
return [
    'description' => 'Add billing address columns to orderhd for the checkout billing address feature',
    'up' => [
        "ALTER TABLE `orderhd`
            ADD COLUMN `billing_company_name` VARCHAR(45) NULL DEFAULT '' AFTER `cc`,
            ADD COLUMN `billing_address` MEDIUMTEXT NULL AFTER `billing_company_name`,
            ADD COLUMN `billing_city` VARCHAR(40) NULL DEFAULT '' AFTER `billing_address`,
            ADD COLUMN `billing_state` CHAR(40) NULL DEFAULT '' AFTER `billing_city`,
            ADD COLUMN `billing_zip` VARCHAR(20) NULL DEFAULT '' AFTER `billing_state`,
            ADD COLUMN `billing_country_code` VARCHAR(2) NULL DEFAULT '' AFTER `billing_zip`",
    ],
    'down' => [
        "ALTER TABLE `orderhd`
            DROP COLUMN `billing_company_name`,
            DROP COLUMN `billing_address`,
            DROP COLUMN `billing_city`,
            DROP COLUMN `billing_state`,
            DROP COLUMN `billing_zip`,
            DROP COLUMN `billing_country_code`",
    ],
];
